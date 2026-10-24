<?php

declare(strict_types=1);

namespace UniScript\Integration;

use PDO;
use RuntimeException;

/** Offline vendor-neutral broker. Profiles select fixtures, never arbitrary network destinations. */
final class DevelopmentServices
{
    private PDO $db;

    private string $namespace;

    private string $transportNamespace;

    private int $remaining;

    private array $observed = ['proposals' => [], 'external_references' => [], 'prescription_references' => [], 'transport' => [], 'resources' => []];

    private readonly ?DevelopmentSandboxTransport $sandbox;

    public function __construct(string $database, private readonly array $envelope, private readonly array $deployment, private readonly array $configuration = [], private readonly array $credentials = [], private readonly array $resources = [], private readonly array $options = [])
    {
        Invocation::fromArray($envelope);
        $this->remaining = $envelope['request_budget'];
        $this->namespace = hash('sha256', json_encode(array_intersect_key($envelope, array_flip(['package_id', 'organization_id', 'location_id', 'connection_id', 'environment'])), JSON_THROW_ON_ERROR));
        $this->transportNamespace = hash('sha256', $this->namespace.':'.self::fingerprint(['credentials' => $credentials, 'configuration' => $configuration]));
        $this->db = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        chmod($database, 0600);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS development_records (scope TEXT NOT NULL, record_key TEXT NOT NULL, revision INTEGER NOT NULL, value TEXT NOT NULL, PRIMARY KEY(scope, record_key))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS development_transports (scope TEXT NOT NULL, logical_key TEXT NOT NULL, body_hash TEXT NOT NULL, status TEXT NOT NULL, handle TEXT NOT NULL, reference TEXT NOT NULL, PRIMARY KEY(scope, logical_key))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS development_typed_references (scope TEXT NOT NULL, handle TEXT NOT NULL, kind TEXT NOT NULL, reference TEXT NOT NULL, PRIMARY KEY(scope,handle))');
        $this->sandbox = $options['sandbox_transport'] ?? DevelopmentSandboxTransport::fromEnvironment(dirname($database).'/sandbox-transports.sqlite');
        if ($this->sandbox !== null && $this->sandbox->packageId() === $envelope['package_id']) {
            $this->sandbox->assertHostOwned($deployment['source_root'] ?? throw new RuntimeException('sandbox_execution_source_binding_missing'));
            $this->sandbox->assertHostOwned($deployment['package_root']);
        }
    }

    public function request(array $request): array
    {
        if (array_diff(array_keys($request), ['invocation_id', 'grant', 'service', 'action', 'arguments']) !== [] || ($request['invocation_id'] ?? null) !== $this->envelope['invocation_id'] || ! is_string($request['grant'] ?? null) || ! hash_equals($this->envelope['service_grant'], $request['grant']) || ! is_array($request['arguments'] ?? null) || $this->remaining-- <= 0 || strtotime($this->envelope['deadline']) <= time()) {
            throw new RuntimeException('development_grant_denied');
        }
        $arguments = $request['arguments'];
        JsonValue::assert($arguments);
        if (strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > PayloadLimits::serviceRequestForPackage($this->envelope['package_id']) || array_intersect(array_keys($arguments), ['organization_id', 'location_id', 'connection_id', 'environment', 'package_id', 'credential', 'token', 'secret', 'url', 'method', 'path', 'headers']) !== []) {
            throw new RuntimeException('development_scope_override');
        }
        $service = ($request['service'] ?? '').'.'.($request['action'] ?? '');
        $allowed = match ($this->envelope['operation']) {
            'identity.describe', 'configuration.validate', 'orders.validate' => [],
            'health.probe', 'connection.test' => ['http.request'],
            'orders.submit', 'orders.reconcile', 'orders.status.update', 'orders.shipping.update' => ['http.request', 'resources.read'],
            'catalog.preview' => ['http.request', 'resources.read'],
            'reference.refresh' => ['http.request', 'storage.read', 'storage.write'],
            'inbound.decode' => ['commands.propose'],
            default => ['http.request', 'storage.read', 'storage.write', 'resources.read'],
        };
        if (! in_array($service, $allowed, true)) {
            throw new RuntimeException('development_operation_service_denied');
        }

        return match ($service) {
            'http.request' => $this->http($arguments),
            'storage.read' => $this->read($arguments),
            'storage.write' => $this->write($arguments),
            'resources.read' => $this->resource($arguments),
            'commands.propose' => $this->propose($arguments),
            default => throw new RuntimeException('development_service_denied'),
        };
    }

    public function evidence(): array
    {
        return $this->observed;
    }

    private function http(array $arguments): array
    {
        if (array_diff(array_keys($arguments), ['profile', 'operation_key', 'parameters', 'idempotency_key', 'body']) !== [] || ($arguments['operation_key'] ?? null) !== $this->envelope['operation'] || ($arguments['idempotency_key'] ?? null) !== $this->envelope['idempotency_key'] || ! is_array($arguments['parameters'] ?? null)) {
            throw new RuntimeException('development_http_binding_denied');
        }
        if ($this->sandbox !== null && $this->sandbox->packageId() === $this->envelope['package_id']) {
            try {
                return $this->sandbox->request($this->envelope, $arguments, $this->configuration, $this->credentials, $this->deployment['package_root']);
            } finally {
                $evidence = $this->sandbox->evidence();
                $this->observed['transport'][] = ['profile' => $arguments['profile'], 'operation_key' => $arguments['operation_key'], 'simulated' => false];
                $this->observed['network_access'] = ($this->observed['network_access'] ?? false) || $evidence['network_access'];
                $this->observed['simulated'] = false;
                $this->observed['external_references'] = [...$this->observed['external_references'], ...$evidence['external_references']];
            }
        }
        $profiles = $this->options['profiles'] ?? JsonDocument::decode(file_get_contents(dirname(__DIR__).'/fixtures/vendor-profiles.json'), true);
        $profile = $profiles[$arguments['profile'] ?? ''] ?? throw new RuntimeException('development_profile_not_registered');
        if (! in_array($this->envelope['operation'], $profile['operations'] ?? [], true)) {
            throw new RuntimeException('development_profile_operation_denied');
        }
        $route = $profile;
        if (isset($profile['routes'])) {
            $selector = $arguments['parameters']['operation_id'] ?? '';
            $route = $profile['routes'][$selector] ?? throw new RuntimeException('development_vendor_operation_not_registered');
        }
        $route = array_replace($route, $route['operation_policies'][$this->envelope['operation']] ?? []);
        if (isset($route['operations']) && ! in_array($this->envelope['operation'], $route['operations'], true)) {
            throw new RuntimeException('development_vendor_operation_binding_denied');
        }
        if (strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > ($profile['max_request_bytes'] ?? 131072)) {
            throw new RuntimeException('development_http_request_limit');
        }
        foreach (['patient_reference_handle' => 'patient', 'prescription_reference_handle' => 'prescription'] as $field => $kind) {
            if (isset($arguments['parameters'][$field])) {
                $this->typedReference($arguments['parameters'][$field], $kind);
            }
        }
        if (($route['reference_kind'] ?? null) === 'order' && ! isset($arguments['parameters']['patient_reference_handle'])) {
            throw new RuntimeException('development_patient_reference_required');
        }
        foreach ($route['reference_fields'] ?? [] as $path => $kind) {
            $this->validateReferenceFields($arguments['body'] ?? [], explode('.', $path), $kind);
        }
        $this->observed['transport'][] = ['profile' => $arguments['profile'], 'operation_key' => $arguments['operation_key'], 'simulated' => true];
        $scenario = $this->envelope['input']['scenario'] ?? 'accepted';
        $mode = $route['mode'] ?? 'read';
        $vendorJson = ($profile['host_result_contract'] ?? null) === 'vendor_json';
        if (! $vendorJson) {
            DevelopmentSandboxTransport::assertInvocationBody($profile, $this->envelope, $arguments);
        }
        $managed = in_array($mode, ['order', 'action', 'reconcile'], true);
        $rejected = static fn (int $status): array => $vendorJson ? ['status' => 'rejected', 'http_status' => $status] : ($managed ? ['status' => 'unknown', 'reason_code' => 'managed_acceptance_unknown'] : ['status' => 'rejected', 'reason_code' => 'managed_read_rejected']);
        $credentialPath = $this->deployment['manifest']['credential_schema'] ?? null;
        if ($credentialPath !== null) {
            try {
                ConfigurationSchema::validateValues(JsonDocument::decode(file_get_contents($this->deployment['package_root'].'/'.$credentialPath)), $this->credentials);
            } catch (\Throwable) {
                return $rejected(401);
            }
        }
        if ($scenario === 'credential_rejected' || array_any($this->credentials, static fn (mixed $value): bool => in_array($value, ['invalid', 'rejected'], true))) {
            return $rejected(401);
        }
        if ($scenario === 'rate_limited') {
            return $vendorJson ? ['status' => 'temporary_failure', 'http_status' => 429, 'retry_after_seconds' => 60] : $rejected(429);
        }
        if ($scenario === 'remote_rejected') {
            return $rejected(422);
        }
        if ($scenario === 'timeout') {
            throw new RuntimeException('development_simulated_timeout');
        }
        if ($scenario === 'malformed_response') {
            return $vendorJson ? ['unexpected' => true] : $rejected(200);
        }
        $logicalKey = $this->envelope['idempotency_key'].(isset($profile['routes']) ? ':'.$this->envelope['operation'].':'.($arguments['parameters']['operation_id'] ?? '') : '');
        $query = $this->db->prepare('SELECT * FROM development_transports WHERE scope=? AND logical_key=?');
        $query->execute([$this->transportNamespace, $logicalKey]);
        $old = $query->fetch(PDO::FETCH_ASSOC);
        if ($mode === 'order') {
            $bodyHash = self::fingerprint(['body' => $arguments['body'] ?? [], 'parameters' => $arguments['parameters'], 'profile' => $arguments['profile']]);
            if ($old !== false && ! hash_equals($old['body_hash'], $bodyHash)) {
                throw new RuntimeException('development_idempotency_conflict');
            }
            if ($old === false) {
                $reference = 'local-order-'.substr(hash('sha256', $this->transportNamespace.':'.$logicalKey), 0, 20);
                $handle = 'external-'.bin2hex(random_bytes(16));
                $insert = $this->db->prepare('INSERT INTO development_transports VALUES (?,?,?,?,?,?)');
                $insert->execute([$this->transportNamespace, $logicalKey, $bodyHash, 'accepted', $handle, $reference]);
                $old = ['status' => 'accepted', 'handle' => $handle, 'reference' => $reference];
            }
            if ($scenario === 'accepted_then_lost') {
                return ['status' => 'unknown'];
            }
            $kind = $route['reference_kind'] ?? 'order';
            $this->recordTypedReference($old['handle'], $kind, $old['reference']);
            if ($kind === 'patient') {
                return ['status' => 'accepted', 'patient_reference_handle' => $old['handle'], 'data' => $this->render($route['response'] ?? [], $old['reference'])];
            }
            if (($route['order_acceptance'] ?? true) === true) {
                $this->observeReference($old);
            }
            $prescriptionHandles = [];
            if (isset($route['reference_kind'])) {
                $count = count($arguments['body']['prescriptions'] ?? [null]);
                for ($index = 0; $index < $count; $index++) {
                    $handle = 'external-'.substr(hash('sha256', $old['handle'].':rx:'.$index), 0, 32);
                    $this->recordTypedReference($handle, 'prescription', $old['reference'].'-rx-'.($index + 1));
                    $prescriptionHandles[] = $handle;
                    $this->observed['prescription_references'][] = ['handle' => $handle, 'reference' => $old['reference'].'-rx-'.($index + 1)];
                }
            }

            return ['status' => 'accepted', ...(($route['order_acceptance'] ?? true) === false ? [] : ['external_reference_handle' => $old['handle']]), ...($prescriptionHandles === [] ? [] : ['prescription_reference_handles' => $prescriptionHandles]), ...($vendorJson ? ['data' => $this->render($route['response'] ?? [], $old['reference'])] : [])];
        }
        if (in_array($mode, ['reconcile', 'action'], true)) {
            $handle = $arguments['parameters']['external_reference_handle'] ?? null;
            if ($handle !== null) {
                $query = $this->db->prepare('SELECT * FROM development_transports WHERE scope=? AND handle=?');
                $query->execute([$this->transportNamespace, $handle]);
                $old = $query->fetch(PDO::FETCH_ASSOC);
            }
            if ($old === false) {
                return ['status' => 'unknown'];
            }
            $this->observeReference($old);

            return ['status' => 'accepted', 'external_reference_handle' => $old['handle'], ...($vendorJson ? ['data' => $this->render($route['response'] ?? [], $old['reference'])] : [])];
        }

        return ['status' => 'accepted', 'data' => $route['response'] ?? ['type' => 'success', 'data' => []]];
    }

    private function read(array $arguments): array
    {
        $this->definition($arguments['key'] ?? '');
        $query = $this->db->prepare('SELECT revision,value FROM development_records WHERE scope=? AND record_key=?');
        $query->execute([$this->namespace, $arguments['key']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return ['record' => $row === false ? null : ['revision' => (int) $row['revision'], 'value' => JsonDocument::decode($row['value'], true)]];
    }

    private function write(array $arguments): array
    {
        if (array_diff(array_keys($arguments), ['key', 'expected_revision', 'value']) !== [] || ! is_int($arguments['expected_revision'] ?? null) || ! is_array($arguments['value'] ?? null)) {
            throw new RuntimeException('development_storage_invalid');
        }
        if (strlen(json_encode($arguments['value'], JSON_THROW_ON_ERROR)) > 65536) {
            throw new RuntimeException('development_storage_value_limit');
        }
        ResourceSchema::validateValues($this->definition($arguments['key'] ?? ''), $arguments['value']);
        $this->db->beginTransaction();
        try {
            $old = $this->read($arguments)['record'];
            $revision = $old['revision'] ?? 0;
            if ($revision !== $arguments['expected_revision']) {
                throw new RuntimeException('development_stale_revision');
            }
            $query = $this->db->prepare('INSERT INTO development_records VALUES (?,?,?,?) ON CONFLICT(scope,record_key) DO UPDATE SET revision=excluded.revision,value=excluded.value');
            $query->execute([$this->namespace, $arguments['key'], $revision + 1, json_encode($arguments['value'], JSON_THROW_ON_ERROR)]);
            $this->db->commit();

            return ['revision' => $revision + 1, 'value' => $arguments['value']];
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function definition(string $key): \stdClass
    {
        $path = $this->deployment['manifest']['resource_schemas'][$key] ?? throw new RuntimeException('development_resource_not_declared');
        Manifest::path($path);

        return JsonDocument::decode(file_get_contents($this->deployment['package_root'].'/'.$path));
    }

    private function resource(array $arguments): array
    {
        $name = $arguments['resource'] ?? '';
        if (! is_string($name) || array_diff(array_keys($arguments), ['resource', 'external_reference_handle', 'resource_handle']) !== []) {
            throw new RuntimeException('development_resource_denied');
        }
        if ($name === 'external_reference') {
            if ($this->sandbox !== null && $this->sandbox->packageId() === $this->envelope['package_id']) {
                return $this->sandbox->referenceStatus($this->envelope, (string) ($arguments['external_reference_handle'] ?? ''), $this->configuration);
            }
            $query = $this->db->prepare('SELECT handle FROM development_transports WHERE scope=? AND handle=?');
            $query->execute([$this->transportNamespace, $arguments['external_reference_handle'] ?? '']);

            return $query->fetchColumn() === false ? ['status' => 'unknown'] : ['status' => 'accepted', 'external_reference_handle' => $arguments['external_reference_handle']];
        }
        if (! array_key_exists($name, $this->resources)) {
            throw new RuntimeException('development_resource_not_granted');
        }
        $this->observed['resources'][] = $name;
        $value = $this->resources[$name];

        return is_array($value) && array_key_exists('records', $value) ? $value : ['records' => $value];
    }

    private function propose(array $arguments): array
    {
        $arguments = CommandProposals::normalizedEvent($arguments);
        if ($this->envelope['operation'] !== 'inbound.decode'
            || ($arguments['receipt_handle'] ?? null) !== ($this->envelope['input']['receipt_handle'] ?? null)
            || ($this->envelope['input']['authenticated_receipt'] ?? null) !== true) {
            throw new RuntimeException('development_proposal_denied');
        }
        $handle = 'proposal-'.hash('sha256', $this->namespace.json_encode($arguments, JSON_THROW_ON_ERROR));
        $this->observed['proposals'][] = ['proposal_handle' => $handle, ...$arguments];

        return ['proposal_handle' => $handle, 'applied' => false, 'receipt_verified' => true, 'status' => 'pending_review'];
    }

    private function observeReference(array $reference): void
    {
        $this->observed['external_references'][] = ['handle' => $reference['handle'], 'reference' => $reference['reference']];
    }

    private static function fingerprint(array $values): string
    {
        $canonical = static function (array $items) use (&$canonical): array {
            if (! array_is_list($items)) {
                ksort($items);
            }
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    $items[$key] = $canonical($value);
                }
            }

            return $items;
        };

        return hash('sha256', json_encode($canonical($values), JSON_THROW_ON_ERROR));
    }

    private function recordTypedReference(string $handle, string $kind, string $reference): void
    {
        $statement = $this->db->prepare('INSERT INTO development_typed_references VALUES (?,?,?,?) ON CONFLICT(scope,handle) DO NOTHING');
        $statement->execute([$this->transportNamespace, $handle, $kind, $reference]);
    }

    private function typedReference(mixed $handle, string $kind): string
    {
        if (! is_string($handle)) {
            throw new RuntimeException('development_owned_reference_required');
        }
        $statement = $this->db->prepare('SELECT reference FROM development_typed_references WHERE scope=? AND handle=? AND kind=?');
        $statement->execute([$this->transportNamespace, $handle, $kind]);
        $value = $statement->fetchColumn();
        if (! is_string($value)) {
            throw new RuntimeException('development_owned_reference_scope_denied');
        }

        return $value;
    }

    private function validateReferenceFields(mixed $value, array $segments, string $kind): void
    {
        if ($segments === []) {
            $this->typedReference($value, $kind);

            return;
        }
        $field = array_shift($segments);
        if (! is_array($value)) {
            throw new RuntimeException('development_reference_field_required');
        }
        if ($field === '*') {
            foreach ($value as $child) {
                $this->validateReferenceFields($child, $segments, $kind);
            }
        } else {
            $this->validateReferenceFields($value[$field] ?? null, $segments, $kind);
        }
    }

    private function render(mixed $value, string $reference): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->render($child, $reference);
            }

            return $value;
        }

        return is_string($value) ? strtr($value, ['{{reference}}' => $reference, '{{credential.clinic_id}}' => $this->credentials['clinic_id'] ?? $this->configuration['clinic_id'] ?? '']) : $value;
    }
}
