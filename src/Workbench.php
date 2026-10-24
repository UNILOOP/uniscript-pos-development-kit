<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Vendor-neutral local host model. Package execution always belongs to the isolated runtime. */
final class Workbench
{
    private readonly WorkbenchState $store;

    private readonly object $runtime;

    private readonly array $fixtureCatalog;

    public function __construct(string $stateDirectory, ?object $runtime = null, ?array $fixtureCatalog = null, ?string $fixtureDirectory = null)
    {
        $this->store = new WorkbenchState($stateDirectory);
        if ($fixtureCatalog === null) {
            $fixtureCatalog = [];
            foreach ([dirname(__DIR__).'/fixtures', $fixtureDirectory] as $directory) {
                if ($directory === null || ! is_dir($directory)) {
                    continue;
                }
                foreach (glob($directory.'/*.json') ?: [] as $path) {
                    if (is_file($path) && ! is_link($path) && filesize($path) <= 131072) {
                        $fixture = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                        if (is_array($fixture) && is_string($fixture['package_id'] ?? null)) {
                            if (! preg_match('/\A[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*\z/D', $fixture['package_id']) || ! is_array($fixture['profiles'] ?? [])) {
                                throw new InvalidArgumentException('local_fixture_identity_or_profiles');
                            }
                            $fixtureCatalog[$fixture['package_id']] = $fixture;
                        } elseif ($directory === $fixtureDirectory) {
                            throw new InvalidArgumentException('local_fixture_package_identity_required');
                        }
                    }
                }
            }
            $fixtureCatalog = array_values($fixtureCatalog);
        }
        $this->fixtureCatalog = $fixtureCatalog;
        $packageProfiles = [];
        foreach ($fixtureCatalog as $fixture) {
            $packageProfiles[$fixture['package_id']] = $fixture['profiles'] ?? [];
        }
        $this->runtime = $runtime ?? new DevelopmentRuntime($stateDirectory.'/runtime', ['package_profiles' => $packageProfiles]);
    }

    public function load(string $source): array
    {
        $execution = WorkbenchState::uuid();
        $deployment = $this->runtime->prepare($source, $execution);
        if (($deployment['execution_revision'] ?? null) !== $execution || ! is_array($deployment['manifest'] ?? null)) {
            throw new RuntimeException('workbench_runtime_binding');
        }

        return $this->store->transaction(function (array &$state) use ($deployment, $execution): array {
            $state['packages'][$execution] = $deployment;
            $this->event($state, 'package.loaded', ['execution' => $execution]);

            return $this->packageSummary($deployment);
        });
    }

    public function snapshot(): array
    {
        return $this->store->transaction(function (array &$state): array {
            return ['simulation' => true, 'mode' => 'Local synthetic host — no payments or production access',
                'vendor_transport' => method_exists($this->runtime, 'networkMode') ? $this->runtime->networkMode() : ['live_sandbox_enabled' => false, 'selected_package_id' => null],
                'catalog' => (new WorkbenchCatalog($this->store))->snapshot($state),
                'reference_timezones' => HostContract::timezones(),
                'packages' => array_values(array_map($this->packageSummary(...), $state['packages'])),
                'connections' => array_values(array_map(fn (array $connection): array => $this->connectionSummary($state, $connection), $state['connections'])),
                'orders' => array_values(array_map(fn (array $order): array => $this->orderSummary($state, $order), $state['orders'])),
                'events' => array_slice($state['events'], -50),
                'fixtures' => ['organization_id' => '00000000-0000-4000-8000-000000000001', 'location_id' => '00000000-0000-4000-8000-000000000002', 'sender_id' => '00000000-0000-4000-8000-000000000003', 'catalog_item_id' => '00000000-0000-4000-8000-000000000004']];
        });
    }

    public function dispatch(string $action, array $input): array
    {
        JsonValue::assert($input);
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > (str_starts_with($action, 'catalog.') ? 1048576 : 131072)) {
            throw new InvalidArgumentException('workbench_request_limit');
        }

        return $this->store->transaction(function (array &$state) use ($action, $input): array {
            if ($action === 'connection.create') {
                return $this->createConnection($state, $input);
            }
            if ($action === 'order.submit') {
                return $this->submit($state, $input);
            }
            if ($action === 'order.action') {
                return $this->orderAction($state, $input);
            }
            $id = self::text($input, 'connection_id');
            $connection = &$state['connections'][$id];
            if (! is_array($connection)) {
                throw new InvalidArgumentException('connection_not_found');
            }
            if ($connection['status'] === 'revoked') {
                throw new InvalidArgumentException('connection_revoked');
            }
            $this->scope($connection, $input);

            return match ($action) {
                'connection.save' => $this->saveConnection($state, $connection, $input),
                'connection.health' => $this->probe($state, $connection, 'health.probe'),
                'connection.test' => $this->probe($state, $connection, 'connection.test'),
                'connection.activate' => $this->activate($state, $connection),
                'connection.pause', 'connection.revoke' => $this->changeStatus($state, $connection, $action === 'connection.pause' ? 'paused' : 'revoked'),
                'connection.upgrade' => $this->upgrade($state, $connection, $input),
                'connection.invoke' => $this->invokeDeclared($state, $connection, $input),
                'mapping.save' => $this->saveMapping($state, $connection, $input),
                'resource.fixture.save' => $this->saveResourceFixture($state, $connection, $input),
                'catalog.stage', 'catalog.apply', 'catalog.cancel', 'catalog.rollback', 'catalog.read' => $this->catalogAction($state, $connection, $action, $input),
                'override.save', 'override.test', 'override.enable', 'override.disable', 'override.remove', 'override.revoke' => $this->senderOverride($state, $connection, $action, $input),
                'webhook.create' => $this->createWebhook($state, $connection, $input),
                'health.schedule' => $this->scheduleHealth($state, $connection, $input),
                default => throw new InvalidArgumentException('workbench_action_unsupported'),
            };
        });
    }

    private function createConnection(array &$state, array $input): array
    {
        $execution = self::text($input, 'execution_revision');
        $package = $state['packages'][$execution] ?? throw new InvalidArgumentException('package_not_found');
        $id = WorkbenchState::uuid();
        $config = self::object($input, 'config');
        $credentials = self::object($input, 'credentials');
        $this->validate($package, 'configuration_schema', $config);
        $this->validate($package, 'credential_schema', $credentials);
        $state['connections'][$id] = ['uuid' => $id, 'package_id' => $package['manifest']['package_id'], 'execution_revision' => $execution, 'lineage' => $id, 'status' => 'draft', 'version' => 1,
            'organization_id' => $input['organization_id'] ?? '00000000-0000-4000-8000-000000000001', 'location_id' => $input['location_id'] ?? '00000000-0000-4000-8000-000000000002', 'environment' => 'sandbox',
            'configuration_revision' => WorkbenchState::uuid(), 'credential_revision' => WorkbenchState::uuid(), 'config' => $config, 'credentials' => $this->store->seal($credentials),
            'configuration_history' => [], 'credential_history' => [], 'health' => null, 'test' => null, 'health_interval_minutes' => 30, 'mappings' => [], 'overrides' => [], 'created_at' => gmdate('c')];
        $this->event($state, 'connection.created', ['connection_id' => $id]);

        return $this->connectionSummary($state, $state['connections'][$id]);
    }

    private function saveConnection(array &$state, array &$connection, array $input): array
    {
        if (($input['expected_version'] ?? null) !== $connection['version']) {
            throw new InvalidArgumentException('connection_revision_conflict');
        }
        $package = $state['packages'][$connection['execution_revision']];
        $config = self::object($input, 'config');
        $credentials = array_key_exists('credentials', $input) ? self::object($input, 'credentials') : $this->store->open($connection['credentials']);
        $this->validate($package, 'configuration_schema', $config);
        $this->validate($package, 'credential_schema', $credentials);
        $connection['configuration_history'][$connection['configuration_revision']] = $connection['config'];
        $connection['credential_history'][$connection['credential_revision']] = $connection['credentials'];
        $connection['config'] = $config;
        $connection['credentials'] = $this->store->seal($credentials);
        $connection['configuration_revision'] = WorkbenchState::uuid();
        $connection['credential_revision'] = WorkbenchState::uuid();
        $connection['version']++;
        $connection['health'] = null;
        $connection['test'] = null;
        foreach ($connection['overrides'] as &$override) {
            $override['tested_revision'] = null;
            $override['tested_configuration_revision'] = null;
            $override['tested_execution_revision'] = null;
            $override['tested_transport_binding'] = null;
        }
        unset($override);
        $this->invalidateSessions($state, $connection['uuid']);
        $this->event($state, 'connection.updated', ['connection_id' => $connection['uuid']]);

        return $this->connectionSummary($state, $connection);
    }

    private function probe(array &$state, array &$connection, string $operation): array
    {
        $connection['package_id'] = $state['packages'][$connection['execution_revision']]['manifest']['package_id'];
        $binding = $this->transportBinding($connection);
        $result = $this->invoke($state, $connection, $operation, []);
        $proof = $this->credentialProof($state, $connection, $this->store->open($connection['credentials']));
        if ($result['kind'] === 'success' && ! $proof['accepted']) {
            $result = (new Result('permanent_failure', 'connection_verification_unproven'))->jsonSerialize();
        }
        if ($binding !== $this->transportBinding($connection)) {
            throw new InvalidArgumentException('connection_transport_profile_changed');
        }
        if ($operation === 'connection.test' && $result['kind'] === 'success' && $proof['verified']) {
            $this->assertAccountAvailable($state, $connection, $proof);
        }
        $key = $operation === 'health.probe' ? 'health' : 'test';
        $connection[$key] = ['successful' => $result['kind'] === 'success', 'checked_at' => time(), 'configuration_revision' => $connection['configuration_revision'], 'credential_revision' => $connection['credential_revision'], 'transport_binding' => $binding, 'verification' => $proof, 'reason_code' => $result['reason_code']];
        $this->event($state, 'connection.'.$key, ['connection_id' => $connection['uuid'], 'kind' => $result['kind'], 'reason_code' => $result['reason_code']]);

        return ['connection' => $this->connectionSummary($state, $connection), 'result' => self::safeResult($result)];
    }

    private function activate(array &$state, array &$connection): array
    {
        if (! $this->fresh($connection, 'health') || ! $this->fresh($connection, 'test')) {
            throw new InvalidArgumentException('fresh_health_and_test_required');
        }
        $binding = $this->transportBinding($connection);
        $proof = $connection['test']['verification'] ?? ['verified' => false];
        if ($proof['verified']) {
            $this->assertAccountAvailable($state, $connection, $proof);
        }
        $validation = $this->invoke($state, $connection, 'configuration.validate', []);
        if ($validation['kind'] !== 'success') {
            throw new InvalidArgumentException('package_configuration_rejected');
        }
        if ($binding !== $this->transportBinding($connection)) {
            throw new InvalidArgumentException('connection_transport_profile_changed');
        }
        if ($proof['verified']) {
            $fingerprint = $proof['account_fingerprint'];
            $owner = $state['account_bindings'][$fingerprint] ?? null;
            $state['account_bindings'][$fingerprint] = $connection['uuid'];
            if ($owner !== null && $owner !== $connection['uuid']) {
                $this->event($state, 'connection.account_transferred', ['source_connection_id' => $owner, 'connection_id' => $connection['uuid'], 'existing_order_pins_changed' => false, 'existing_order_drain_retained' => true]);
            }
        }
        foreach ($state['connections'] as &$other) {
            if ($other['uuid'] !== $connection['uuid'] && $other['status'] === 'active' && $other['location_id'] === $connection['location_id'] && $other['organization_id'] === $connection['organization_id'] && $other['lineage'] === $connection['lineage']) {
                $other['status'] = 'paused';
                $this->invalidateSessions($state, $other['uuid']);
            }
        }
        unset($other);
        $connection['status'] = 'active';
        $connection['version']++;
        foreach ($state['endpoints'] as &$endpoint) {
            if ($endpoint['lineage'] === $connection['lineage']) {
                $endpoint['current_connection'] = $connection['uuid'];
            }
        }
        unset($endpoint);
        $this->invalidateSessions($state, $connection['uuid']);
        $this->event($state, 'connection.activated', ['connection_id' => $connection['uuid']]);

        return $this->connectionSummary($state, $connection);
    }

    private function changeStatus(array &$state, array &$connection, string $status): array
    {
        $connection['status'] = $status;
        $connection['version']++;
        $this->invalidateSessions($state, $connection['uuid']);
        $this->event($state, 'connection.'.$status, ['connection_id' => $connection['uuid']]);

        return $this->connectionSummary($state, $connection);
    }

    private function upgrade(array &$state, array $connection, array $input): array
    {
        $target = self::text($input, 'execution_revision');
        $package = $state['packages'][$target] ?? throw new InvalidArgumentException('package_not_found');
        $original = $state['packages'][$connection['execution_revision']];
        if ($package['manifest']['package_id'] !== $original['manifest']['package_id'] || $target === $connection['execution_revision']) {
            throw new InvalidArgumentException('upgrade_package_mismatch');
        }
        $this->validate($package, 'configuration_schema', $connection['config']);
        if (($input['copy_credentials'] ?? false) !== true) {
            throw new InvalidArgumentException('upgrade_credential_copy_confirmation_required');
        }
        $credentials = $this->store->open($connection['credentials']);
        $this->validate($package, 'credential_schema', $credentials);
        foreach ($connection['mappings'] as $mapping) {
            $this->validateResource($package, 'order_mapping', $mapping['values']);
        }
        foreach ($connection['resource_fixtures'] ?? [] as $type => $records) {
            foreach ($records as $record) {
                $this->validateResource($package, $type, $record['values']);
            }
        }
        $id = WorkbenchState::uuid();
        $copy = $connection;
        $copy['uuid'] = $id;
        $copy['execution_revision'] = $target;
        $copy['status'] = 'draft';
        $copy['version'] = 1;
        $copy['configuration_revision'] = WorkbenchState::uuid();
        $copy['credential_revision'] = WorkbenchState::uuid();
        $copy['health'] = null;
        $copy['test'] = null;
        $copy['upgraded_from'] = $connection['uuid'];
        foreach ($copy['overrides'] as $sender => &$override) {
            if (in_array($this->overrideState($override), ['removed', 'revoked'], true)) {
                unset($copy['overrides'][$sender]);

                continue;
            }
            $override['enabled'] = false;
            $override['state'] = 'draft';
            $override['tested_revision'] = null;
            $override['tested_configuration_revision'] = null;
            $override['tested_execution_revision'] = null;
            $override['tested_transport_binding'] = null;
        }
        unset($override);
        $state['connections'][$id] = $copy;
        $this->event($state, 'connection.upgrade_drafted', ['connection_id' => $id]);

        return $this->connectionSummary($state, $copy);
    }

    private function saveMapping(array &$state, array &$connection, array $input): array
    {
        if (isset($input['resource_type']) && $input['resource_type'] !== 'order_mapping') {
            throw new InvalidArgumentException('use_resource_fixture_editor');
        }
        $item = self::text($input, 'catalog_item_id');
        $revision = $connection['mappings'][$item]['revision'] ?? 0;
        if (($input['expected_revision'] ?? null) !== $revision) {
            throw new InvalidArgumentException('mapping_revision_conflict');
        }
        $values = self::object($input, 'values');
        $this->validateResource($state['packages'][$connection['execution_revision']], 'order_mapping', $values);
        if (isset($connection['mappings'][$item])) {
            $connection['mapping_history'][$item][$revision] = $connection['mappings'][$item];
        }
        $connection['mappings'][$item] = ['values' => $values, 'revision' => $revision + 1, 'status' => 'published', 'uuid' => WorkbenchState::uuid()];
        $this->invalidateSessions($state, $connection['uuid']);
        $this->event($state, 'mapping.saved', ['connection_id' => $connection['uuid'], 'revision' => $revision + 1]);

        return ['revision' => $revision + 1, 'status' => 'published', 'host_reload_required' => true];
    }

    private function saveResourceFixture(array &$state, array &$connection, array $input): array
    {
        $type = self::text($input, 'resource_type');
        $key = self::text($input, 'resource_key');
        if ($type === 'order_mapping' || ! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $type)) {
            throw new InvalidArgumentException('resource_fixture_type_invalid');
        }
        $old = $connection['resource_fixtures'][$type][$key] ?? null;
        $revision = $old['revision'] ?? 0;
        if (($input['expected_revision'] ?? null) !== $revision) {
            throw new InvalidArgumentException('resource_revision_conflict');
        }
        $values = self::object($input, 'values');
        $this->validateResource($state['packages'][$connection['execution_revision']], $type, $values);
        if ($old !== null) {
            $connection['resource_fixture_history'][$type][$key][$revision] = $old;
        }
        $connection['resource_fixtures'][$type][$key] = ['revision' => $revision + 1, 'values' => $values, 'local_fixture_only' => true];
        $this->event($state, 'resource.fixture_saved', ['connection_id' => $connection['uuid'], 'resource_type' => $type, 'revision' => $revision + 1]);

        return ['revision' => $revision + 1, 'local_fixture_only' => true];
    }

    private function senderOverride(array &$state, array &$connection, string $action, array $input): array
    {
        $sender = self::text($input, 'sender_id');
        if ($action === 'override.save') {
            $credentials = self::object($input, 'credentials');
            $this->validate($state['packages'][$connection['execution_revision']], 'credential_schema', $credentials);
            $old = $connection['overrides'][$sender] ?? null;
            $history = $old['history'] ?? [];
            if ($old !== null) {
                $historical = $old;
                unset($historical['history']);
                $history[$old['revision']] = $historical;
            }
            $connection['overrides'][$sender] = ['revision' => WorkbenchState::uuid(), 'credentials' => $this->store->seal($credentials), 'state' => 'draft', 'enabled' => false, 'tested_revision' => null, 'tested_configuration_revision' => null, 'tested_execution_revision' => null, 'tested_transport_binding' => null, 'revoked' => false, 'history' => $history];
        } else {
            $override = &$connection['overrides'][$sender];
            if (! is_array($override)) {
                throw new InvalidArgumentException('sender_override_unavailable');
            }
            $target = $input['revision'] ?? $override['revision'];
            if (! is_string($target) || $target !== $override['revision']) {
                if ($action !== 'override.revoke') {
                    throw new InvalidArgumentException('sender_override_revision_changed');
                }
                $found = false;
                foreach ($override['history'] as &$historical) {
                    if (($historical['revision'] ?? null) === $target) {
                        $historical['revoked'] = true;
                        $historical['enabled'] = false;
                        $historical['state'] = 'revoked';
                        $found = true;
                    }
                }
                unset($historical);
                if (! $found) {
                    throw new InvalidArgumentException('sender_override_unavailable');
                }
                $this->event($state, $action, ['connection_id' => $connection['uuid'], 'credential_revision' => $target]);

                return $this->connectionSummary($state, $connection);
            }
            if ($override['revoked'] && ! in_array($action, ['override.remove', 'override.revoke'], true)) {
                throw new InvalidArgumentException('sender_override_unavailable');
            }
            if ($action === 'override.test') {
                $binding = $this->transportBinding($connection);
                $credentials = $this->store->open($override['credentials']);
                $scope = ['sender_id' => $sender, 'credential_revision' => $override['revision']];
                $result = $this->invoke($state, $connection, 'connection.test', [], [], $credentials, $scope);
                $proof = $this->credentialProof($state, $connection, $credentials, $scope);
                if ($result['kind'] === 'success' && ! $proof['accepted']) {
                    $result = (new Result('permanent_failure', 'sender_verification_unproven'))->jsonSerialize();
                }
                if ($binding !== $this->transportBinding($connection)) {
                    throw new InvalidArgumentException('connection_transport_profile_changed');
                }
                $override['verification'] = $proof;
                $override['tested_revision'] = $result['kind'] === 'success' ? $override['revision'] : null;
                $override['tested_configuration_revision'] = $result['kind'] === 'success' ? $connection['configuration_revision'] : null;
                $override['tested_execution_revision'] = $result['kind'] === 'success' ? $connection['execution_revision'] : null;
                $override['tested_transport_binding'] = $result['kind'] === 'success' ? $binding : null;
                if ($result['kind'] !== 'success') {
                    $override['enabled'] = false;
                    $override['state'] = 'draft';
                }
            } elseif ($action === 'override.enable') {
                if (! $this->overrideTestCurrent($connection, $override)) {
                    throw new InvalidArgumentException('sender_override_test_required');
                }
                $override['enabled'] = true;
                $override['state'] = 'enabled';
            } else {
                $override['enabled'] = false;
                $override['state'] = match ($action) {
                    'override.remove' => 'removed', 'override.revoke' => 'revoked', default => 'disabled'
                };
                $override['revoked'] = $override['revoked'] || $action === 'override.revoke';
            }
        }
        $this->event($state, $action, ['connection_id' => $connection['uuid']]);

        return $this->connectionSummary($state, $connection);
    }

    private function createWebhook(array &$state, array $connection, array $input): array
    {
        foreach ($state['endpoints'] as $endpoint) {
            if ($endpoint['lineage'] === $connection['lineage']) {
                return ['uuid' => $endpoint['uuid'], 'path' => '/pos-webhooks/'.$endpoint['uuid']];
            }
        }
        $username = self::text($input, 'username');
        $password = self::text($input, 'password');
        if (strlen($password) < 24) {
            throw new InvalidArgumentException('webhook_password_minimum_24_characters');
        }
        $id = WorkbenchState::uuid();
        $state['endpoints'][$id] = ['uuid' => $id, 'lineage' => $connection['lineage'], 'profile' => $this->inboundProfile($state['packages'][$connection['execution_revision']]), 'current_connection' => $connection['uuid'], 'credentials' => $this->store->seal(['username' => $username, 'password' => $password])];
        $this->event($state, 'webhook.created', ['connection_id' => $connection['uuid']]);

        return ['uuid' => $id, 'path' => '/pos-webhooks/'.$id];
    }

    private function scheduleHealth(array &$state, array &$connection, array $input): array
    {
        $minutes = $input['interval_minutes'] ?? null;
        if (! is_int($minutes) || $minutes < 5 || $minutes > 1440) {
            throw new InvalidArgumentException('health_interval_invalid');
        }
        $connection['health_interval_minutes'] = $minutes;
        if (($input['run_due_now'] ?? false) === true) {
            return $this->probe($state, $connection, 'health.probe');
        }

        return $this->connectionSummary($state, $connection);
    }

    private function invokeDeclared(array &$state, array $connection, array $input): array
    {
        $operation = self::text($input, 'operation');
        if (str_starts_with($operation, 'orders.') || $operation === 'inbound.decode') {
            throw new InvalidArgumentException('use_scoped_order_or_callback_workflow');
        }
        $result = $this->invoke($state, $connection, $operation, self::object($input, 'input'));
        $this->event($state, 'operation.invoked', ['connection_id' => $connection['uuid'], 'operation' => $operation, 'kind' => $result['kind'], 'reason_code' => $result['reason_code']]);

        $data = null;
        if ($result['kind'] === 'success' && $operation === 'catalog.preview') {
            $proposals = $result['data']['proposals'] ?? null;
            if (is_array($proposals) && array_is_list($proposals) && count($proposals) <= 1000) {
                $safe = array_map(WorkbenchCatalog::proposal(...), $proposals);
                foreach ($safe as $proposal) {
                    if ($proposal['mapping_values'] !== null) {
                        $this->validateResource($state['packages'][$connection['execution_revision']], 'order_mapping', $proposal['mapping_values']);
                    }
                }
                $data = ['proposals' => $safe, 'applied' => false];
            }
        } elseif ($result['kind'] === 'success' && $operation === 'reference.inspect' && array_key_exists('record', $result['data'])) {
            $record = $result['data']['record'];
            if ($record === null) {
                $data = ['record' => null];
            } elseif (is_array($record) && is_int($record['revision'] ?? null) && $record['revision'] > 0 && is_array($record['value'] ?? null) && strlen(json_encode($record, JSON_THROW_ON_ERROR)) <= 65536) {
                $this->validateResource($state['packages'][$connection['execution_revision']], 'reference', $record['value']);
                $data = ['record' => ['revision' => $record['revision'], 'value' => $record['value']]];
            }
        }

        return ['result' => self::safeResult($result), 'data' => $data, 'projection_scope' => $data === null ? 'result_metadata_only' : 'typed_catalog_or_reference'];
    }

    private function catalogAction(array &$state, array $connection, string $action, array $input): array
    {
        $catalog = new WorkbenchCatalog($this->store);
        if ($action === 'catalog.read') {
            return $catalog->read($state, $connection['uuid'], self::text($input, 'import_uuid'), $input['after_row'] ?? 0);
        }
        if (($input['expected_version'] ?? null) !== $connection['version'] || ($input['confirmed'] ?? false) !== true) {
            throw new InvalidArgumentException('catalog_confirmation_or_connection_revision_required');
        }
        if ($action === 'catalog.stage') {
            if (! is_string($input['source_csv'] ?? null)) {
                throw new InvalidArgumentException('catalog_csv_source_required');
            }
            $records = WorkbenchCatalog::csv($input['source_csv']);
            $result = $catalog->stage($state, $connection, $records, $input['expected_version'], fn (array $records): array => $this->invoke($state, $connection, 'catalog.preview', ['records' => $records]), fn (array $values) => $this->validateResource($state['packages'][$connection['execution_revision']], 'order_mapping', $values));
        } else {
            if (! is_int($input['expected_revision'] ?? null)) {
                throw new InvalidArgumentException('catalog_import_revision_required');
            }
            $result = $catalog->mutate($state, $connection, self::text($input, 'import_uuid'), $input['expected_revision'], substr($action, 8), $this->fresh($connection, 'health') && $this->fresh($connection, 'test'));
        }
        $this->event($state, $action, ['connection_id' => $connection['uuid'], 'import_uuid' => $result['uuid'], 'revision' => $result['revision'], 'status' => $result['status']]);

        return $result;
    }

    private function submit(array &$state, array $input): array
    {
        $connection = $state['connections'][self::text($input, 'connection_id')] ?? throw new InvalidArgumentException('connection_not_found');
        $this->scope($connection, $input);
        if ($connection['status'] !== 'active' || ! $this->fresh($connection, 'health') || ! $this->fresh($connection, 'test')) {
            throw new InvalidArgumentException('active_connection_and_fresh_evidence_required');
        }
        $key = self::text($input, 'idempotency_key');
        $requestFingerprint = hash('sha256', self::canonical(['sender_id' => $input['sender_id'] ?? null, 'catalog_item_id' => $input['catalog_item_id'] ?? null, 'input' => $input['input'] ?? []]));
        foreach ($state['orders'] as $existing) {
            if ($existing['connection_id'] === $connection['uuid'] && $existing['idempotency_key'] === $key) {
                if (! hash_equals($existing['request_fingerprint'], $requestFingerprint)) {
                    throw new InvalidArgumentException('order_idempotency_conflict');
                }

                return $this->orderSummary($state, $existing);
            }
        }
        $sender = self::text($input, 'sender_id');
        $item = self::text($input, 'catalog_item_id');
        $mapping = $connection['mappings'][$item] ?? throw new InvalidArgumentException('published_mapping_required');
        $body = self::object($input, 'input');
        $this->assertNonControlledSubmission($body, $mapping['values']);
        $override = $connection['overrides'][$sender] ?? null;
        $usesOverride = $override !== null && $this->overrideState($override) !== 'removed';
        if ($usesOverride && (! $override['enabled'] || $override['revoked'])) {
            throw new InvalidArgumentException('sender_override_not_enabled');
        }
        if ($usesOverride && ! $this->overrideTestCurrent($connection, $override)) {
            throw new InvalidArgumentException('sender_override_test_required');
        }
        $credentials = $this->store->open($usesOverride ? $override['credentials'] : $connection['credentials']);
        $credentialRevision = $usesOverride ? $override['revision'] : $connection['credential_revision'];
        $id = WorkbenchState::uuid();
        $body['order_id'] = $id;
        if (isset($body['order']) && is_array($body['order'])) {
            $body['order']['order_id'] = $id;
            foreach ($body['order']['medications'] ?? [] as $index => $medication) {
                $body['order']['medications'][$index]['mapping'] = $mapping['values'];
            }
        }
        $resources = ['order.mapping' => $mapping['values'], 'mapping' => $mapping['values'], 'order_mapping' => $mapping['values']];
        $scope = ['order_id' => $id, 'sender_id' => $sender, 'credential_revision' => $credentialRevision, 'mapping_revision' => (string) $mapping['revision'], 'idempotency_key' => $key];
        $validation = $this->invoke($state, $connection, 'orders.validate', $body, $resources, $credentials, $scope);
        $result = $validation['kind'] === 'success' ? $this->invoke($state, $connection, 'orders.submit', $body, $resources, $credentials, $scope) : $validation;
        $evidence = method_exists($this->runtime, 'lastEvidence') ? $this->runtime->lastEvidence() : [];
        $references = [];
        foreach ($evidence['external_references'] ?? [] as $reference) {
            if (is_string($reference['handle'] ?? null) && is_string($reference['reference'] ?? null)) {
                $references[] = ['handle' => $reference['handle'], 'fingerprint' => substr(hash('sha256', $reference['reference']), 0, 12), 'reference' => $this->store->seal(['value' => $reference['reference']])];
            }
        }
        if ($references === [] && $result['kind'] === 'remote_acceptance') {
            $result = (new Result('unknown_remote_outcome', 'workbench.acceptance_not_verified', [], [], ['action' => 'operator_review', 'resubmit_allowed' => false]))->jsonSerialize();
        }
        $state['orders'][$id] = ['uuid' => $id, 'connection_id' => $connection['uuid'], 'lineage' => $connection['lineage'], 'organization_id' => $connection['organization_id'], 'location_id' => $connection['location_id'], 'sender_id' => $sender, 'catalog_item_id' => $item, 'environment' => 'sandbox', 'execution_revision' => $connection['execution_revision'], 'configuration_revision' => $connection['configuration_revision'], 'credential_revision' => $credentialRevision, 'transport_binding' => $this->transportBinding($connection), 'mapping_revision' => $mapping['revision'], 'config' => $connection['config'], 'credentials' => $this->store->seal($credentials), 'mapping' => $mapping['values'], 'input' => $this->store->seal($body), 'idempotency_key' => $key, 'request_fingerprint' => $requestFingerprint, 'sender_override' => $usesOverride, 'route_version' => 1, 'delivery_status' => $this->resultDeliveryStatus($result['kind']), 'status' => $result['kind'] === 'remote_acceptance' ? 'submitted_to_pos' : 'queued_for_pos', 'result' => self::safeResult($result), 'references' => $references, 'fulfillments' => [], 'event_ids' => [], 'created_at' => gmdate('c')];
        $this->event($state, 'order.submitted', ['order_id' => $id, 'kind' => $result['kind'], 'reason_code' => $result['reason_code']]);

        return $this->orderSummary($state, $state['orders'][$id]);
    }

    private function assertNonControlledSubmission(array $input, array $mapping): void
    {
        $claims = [$input, $mapping];
        if (is_array($input['order'] ?? null)) {
            $claims[] = $input['order'];
            if (is_array($input['order']['medications'] ?? null)) {
                $claims = [...$claims, ...$input['order']['medications']];
            }
        }
        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $controlled = array_key_exists('verified_signed_payload', $claim);
            foreach (['controlled', 'is_controlled', 'controlled_substance'] as $field) {
                $controlled = $controlled || (array_key_exists($field, $claim) && $claim[$field] !== false && $claim[$field] !== null);
            }
            $controlled = $controlled || in_array($claim['schedule_code'] ?? null, ['2', '3', '4', '5', 2, 3, 4, 5], true);
            if ($controlled) {
                throw new InvalidArgumentException('controlled_orders_require_host_owned_verification');
            }
        }
    }

    private function orderAction(array &$state, array $input): array
    {
        $id = self::text($input, 'order_id');
        $order = &$state['orders'][$id];
        if (! is_array($order)) {
            throw new InvalidArgumentException('order_not_found');
        }
        if (($input['audience'] ?? 'pharmacy') !== 'pharmacy') {
            throw new InvalidArgumentException('sender_order_actions_denied');
        }
        $operation = self::text($input, 'operation');
        if (! in_array($operation, ['orders.reconcile', 'orders.status.update', 'orders.shipping.update'], true)) {
            throw new InvalidArgumentException('order_action_unsupported');
        }
        $connection = $state['connections'][$order['connection_id']];
        $this->assertOrderCredentialsAvailable($connection, $order);
        $this->scope($connection, $input);
        $connection['execution_revision'] = $order['execution_revision'];
        $connection['config'] = $order['config'];
        $connection['configuration_revision'] = $order['configuration_revision'];
        if (($order['transport_binding'] ?? null) !== $this->transportBinding($connection)) {
            throw new InvalidArgumentException('order_transport_profile_changed');
        }
        if (array_diff(array_keys($input), ['order_id', 'audience', 'organization_id', 'location_id', 'environment', 'operation', 'expected_revision', 'idempotency_key', 'canonical_code', 'shipping']) !== []) {
            throw new InvalidArgumentException('order_action_parameters_invalid');
        }
        $key = self::text($input, 'idempotency_key');
        $expected = $input['expected_revision'] ?? null;
        if (! preg_match('/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/iD', $key) || ! is_int($expected) || $expected < 0) {
            throw new InvalidArgumentException('order_action_identity_invalid');
        }
        if ($operation !== 'orders.reconcile' && ! in_array($order['status'], ['submitted_to_pos', 'accepted', 'in_progress', 'ready'], true)) {
            throw new InvalidArgumentException('order_action_not_ready');
        }
        $parameters = array_intersect_key($input, array_flip(['canonical_code', 'shipping']));
        $fingerprint = hash('sha256', self::canonical(['operation' => $operation, 'parameters' => $parameters]));
        foreach ($order['actions'] ?? [] as $existing) {
            if (($existing['idempotency_key'] ?? null) === $key) {
                if (! hash_equals($existing['request_fingerprint'], $fingerprint)) {
                    throw new InvalidArgumentException('order_action_idempotency_conflict');
                }

                return ['order' => $this->orderSummary($state, $order), 'action' => $this->actionSummary($existing), 'result' => $existing['result']];
            }
        }
        if (($order['action_revision'] ?? 0) !== $expected) {
            throw new InvalidArgumentException('order_action_revision_conflict');
        }
        if ($operation !== 'orders.reconcile' && array_any($order['actions'] ?? [], static fn (array $action): bool => $action['status'] === 'unknown')) {
            throw new InvalidArgumentException('order_action_prior_outcome_requires_review');
        }
        if (count($order['references']) !== 1) {
            throw new InvalidArgumentException('order_action_owned_reference_required');
        }
        $reference = $order['references'][0];
        if (method_exists($this->runtime, 'referenceIsAmbiguous') && $this->runtime->referenceIsAmbiguous($reference['handle'])) {
            throw new InvalidArgumentException('order_reference_ambiguous');
        }
        $binding = $this->transportBinding($connection);
        [$runtimeInput, $mapping] = $this->actionInput($state['packages'][$order['execution_revision']], $connection, $reference['handle'], $operation, $parameters);
        $actionId = WorkbenchState::uuid();
        $result = $this->invoke($state, $connection, $operation, $runtimeInput, ['order.mapping' => $order['mapping']], $this->store->open($order['credentials']), ['order_id' => $id, 'credential_revision' => $order['credential_revision'], 'idempotency_key' => $key]);
        $evidence = method_exists($this->runtime, 'lastEvidence') ? $this->runtime->lastEvidence() : [];
        $accepted = $result['kind'] === 'remote_acceptance' && ($result['data']['external_reference_handle'] ?? null) === $reference['handle']
            && array_any($evidence['external_references'] ?? [], static fn (array $observed): bool => ($observed['handle'] ?? null) === $reference['handle']);
        if ($binding !== $this->transportBinding($connection) || ($result['kind'] === 'remote_acceptance' && ! $accepted) || ($operation !== 'orders.reconcile' && $result['kind'] === 'success')) {
            $result = (new Result('unknown_remote_outcome', 'workbench.action_acceptance_not_verified', [], [], ['action' => 'operator_review', 'resubmit_allowed' => false]))->jsonSerialize();
        }
        $order['action_revision'] = $expected + 1;
        $action = ['uuid' => $actionId, 'revision' => $expected + 1, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'operation' => $operation,
            'parameters' => $this->store->seal($parameters), 'runtime_input' => $this->store->seal($runtimeInput), 'mapping' => $mapping,
            'status' => in_array($result['kind'], ['success', 'remote_acceptance'], true) ? 'completed' : ($result['kind'] === 'unknown_remote_outcome' ? 'unknown' : 'failed'), 'result' => self::safeResult($result), 'created_at' => gmdate('c')];
        $order['actions'][] = $action;
        $this->event($state, 'order.action', ['order_id' => $id, 'operation' => $operation, 'kind' => $result['kind'], 'reason_code' => $result['reason_code']]);

        return ['order' => $this->orderSummary($state, $order), 'action' => $this->actionSummary($action), 'result' => self::safeResult($result)];
    }

    private function actionInput(array $package, array $connection, string $reference, string $operation, array $parameters): array
    {
        $input = ['external_reference_handle' => $reference];
        if ($operation === 'orders.reconcile') {
            if ($parameters !== []) {
                throw new InvalidArgumentException('order_reconcile_parameters_forbidden');
            }

            return [$input, null];
        }
        $code = $parameters['canonical_code'] ?? null;
        if (! is_string($code) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}\z/D', $code) || ($operation === 'orders.status.update' && array_key_exists('shipping', $parameters))) {
            throw new InvalidArgumentException('order_action_canonical_mapping_required');
        }
        $resource = $operation === 'orders.status.update' ? 'status_mapping' : 'shipping_mapping';
        $mapping = $connection['resource_fixtures'][$resource][$code] ?? null;
        $vendor = $mapping['values']['vendor_code'] ?? null;
        if (! is_string($vendor) || $vendor === '') {
            throw new InvalidArgumentException('order_action_vendor_mapping_required');
        }
        $payloadSchema = $operation === 'orders.status.update' ? 'order_status_update' : 'order_shipping_update';
        $schema = $this->schema($package, $payloadSchema, true) ?? throw new InvalidArgumentException('order_action_schema_required');
        $this->assertActionSchemaSafe($schema);
        if ($operation === 'orders.status.update') {
            $payload = ['status' => $vendor];
            $allowed = ['allowed_status_codes' => [$vendor]];
        } else {
            $shipping = $parameters['shipping'] ?? null;
            if (! is_array($shipping) || ! JsonValue::object($shipping) || array_key_exists('service', $shipping)) {
                throw new InvalidArgumentException('order_action_shipping_mapping_invalid');
            }
            $type = $schema['properties']['shipping']['properties']['service']['type'] ?? null;
            if ($type === 'integer') {
                if (! preg_match('/\A[1-9][0-9]{0,8}\z/D', $vendor)) {
                    throw new InvalidArgumentException('order_action_shipping_mapping_invalid');
                }
                $service = (int) $vendor;
            } elseif ($type === 'string' && $package['manifest']['package_id'] !== 'uniscript/lifefile-v2') {
                $service = $vendor;
            } else {
                throw new InvalidArgumentException('order_action_shipping_mapping_invalid');
            }
            $payload = ['shipping' => ['service' => $service, ...$shipping]];
            $allowed = ['allowed_shipping_codes' => [$service]];
        }
        $this->validateResource($package, $payloadSchema, $payload);
        $input = [...$input, ...$payload, ...$allowed];
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 65536) {
            throw new InvalidArgumentException('order_action_input_limit');
        }

        return [$input, ['resource' => $resource, 'key' => $code, 'revision' => $mapping['revision'], 'sha256' => hash('sha256', self::canonical($mapping['values']))]];
    }

    private function assertActionSchemaSafe(array $schema): void
    {
        foreach ($schema['properties'] ?? [] as $field => $definition) {
            if (preg_match('/(?:^|_)(?:password|secret|token|credentials?|api_?key|private_?key|authorization|username|auth)(?:_|$)/i', $field)
                || in_array($field, ['external_reference_handle', 'allowed_status_codes', 'allowed_shipping_codes', 'config', 'organization_id', 'location_id', 'connection_id', 'actor_id'], true)) {
                throw new InvalidArgumentException('order_action_sensitive_schema');
            }
            if (is_array($definition)) {
                $this->assertActionSchemaSafe($definition);
            }
        }
        if (is_array($schema['items'] ?? null)) {
            $this->assertActionSchemaSafe($schema['items']);
        }
    }

    private function actionSummary(array $action): array
    {
        return ['uuid' => $action['uuid'], 'revision' => $action['revision'], 'operation' => $action['operation'], 'status' => $action['status'], 'result_kind' => $action['result']['kind'], 'created_at' => $action['created_at']];
    }

    public function receive(string $endpointId, string $username, string $password, string $body): array
    {
        if (strlen($body) > 65536) {
            throw new InvalidArgumentException('webhook_body_limit');
        }

        return $this->store->transaction(function (array &$state) use ($endpointId, $username, $password, $body): array {
            $endpoint = $state['endpoints'][$endpointId] ?? throw new RuntimeException('webhook_unauthorized');
            $credentials = $this->store->open($endpoint['credentials']);
            if (! hash_equals($credentials['username'], $username) || ! hash_equals($credentials['password'], $password)) {
                throw new RuntimeException('webhook_unauthorized');
            }
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || $payload === []) {
                throw new InvalidArgumentException('webhook_json_object_required');
            }
            $sources = array_is_list($payload) ? $payload : ($payload['events'] ?? [$payload]);
            if (! is_array($sources) || ! array_is_list($sources) || $sources === [] || count($sources) > 100
                || array_any($sources, static fn (mixed $source): bool => ! is_array($source) || array_is_list($source))) {
                throw new InvalidArgumentException('webhook_events_invalid');
            }
            $hash = hash('sha256', $endpointId.'|'.$body);
            foreach ($state['receipts'] as $receipt) {
                if (hash_equals($receipt['fingerprint'], $hash)) {
                    return ['receipt_handle' => $receipt['uuid'], 'status' => $receipt['status'], 'duplicate' => true];
                }
            }
            $profile = $endpoint['profile'] ?? $this->inboundProfile($state['packages'][$state['connections'][$endpoint['current_connection']]['execution_revision']]);
            $orderIds = [];
            $decoder = null;
            foreach ($sources as $index => $source) {
                $orderId = $this->findCallbackOrder($state, $endpoint, $source);
                $orderIds[$index] = $orderId;
                $order = $state['orders'][$orderId];
                $binding = $order['connection_id'].':'.$order['execution_revision'];
                if ($decoder !== null && $decoder !== $binding) {
                    throw new InvalidArgumentException('callback_decoder_binding_ambiguous');
                }
                $decoder = $binding;
                foreach ($this->callbackAccounts($profile, $order['config']) as $field => $expected) {
                    $actual = InboundProfile::value($source, $field);
                    if ((! is_string($actual) && ! is_int($actual)) || ! hash_equals($expected, (string) $actual)) {
                        throw new InvalidArgumentException('callback_account_mismatch');
                    }
                }
            }
            $firstOrder = $state['orders'][$orderIds[0]];
            $connection = $state['connections'][$firstOrder['connection_id']];
            $connection['execution_revision'] = $firstOrder['execution_revision'];
            $connection['config'] = $firstOrder['config'];
            $connection['configuration_revision'] = $firstOrder['configuration_revision'];
            $id = WorkbenchState::uuid();
            $result = $this->invoke($state, $connection, 'inbound.decode', ['authenticated_receipt' => true, 'receipt_handle' => $id, 'events' => $sources], ['inbound.receipt' => ['handle' => $id, 'body' => $payload, 'order_handle' => $firstOrder['uuid']], 'order.mapping' => $firstOrder['mapping']], $this->store->open($firstOrder['credentials']), ['order_id' => $firstOrder['uuid'], 'receipt_id' => $id]);
            $evidence = method_exists($this->runtime, 'lastEvidence') ? $this->runtime->lastEvidence() : [];
            $applied = false;
            $review = false;
            foreach ($result['kind'] === 'success' ? ($evidence['proposals'] ?? []) : [] as $proposal) {
                if (($proposal['receipt_handle'] ?? null) !== $id || ! is_array($proposal['event'] ?? null)) {
                    $review = true;

                    continue;
                }
                $event = $proposal['event'];
                $matches = array_keys(array_filter($sources, static function (array $source) use ($event, $profile): bool {
                    $reference = InboundProfile::value($source, $profile['reference_field']);
                    $sourceId = InboundProfile::value($source, $profile['event_id_field']);
                    $eventId = $event['event_id'] ?? null;

                    return (is_string($reference) || is_int($reference)) && (string) $reference === ($event['external_order_reference'] ?? null)
                        && ($eventId === null || $eventId === '' || ((is_string($sourceId) || is_int($sourceId)) && (string) $sourceId === $eventId));
                }));
                if (count($matches) !== 1) {
                    $review = true;

                    continue;
                }
                $sourceIndex = $matches[0];
                $order = &$state['orders'][$orderIds[$sourceIndex]];
                $accepted = $this->applyProposal($order, $proposal, $sources[$sourceIndex], $profile);
                $applied = $accepted || $applied;
                $review = ! $accepted || $review;
                unset($order);
            }
            $state['receipts'][$id] = ['uuid' => $id, 'fingerprint' => $hash, 'order_id' => $firstOrder['uuid'], 'order_ids' => array_values(array_unique($orderIds)), 'execution_revision' => $firstOrder['execution_revision'], 'status' => $result['kind'] === 'success' && $applied && ! $review ? 'processed' : 'needs_review', 'reason_code' => $result['reason_code'], 'payload' => $this->store->seal($payload)];
            $this->event($state, 'webhook.received', ['order_id' => $firstOrder['uuid'], 'receipt_handle' => $id, 'status' => $state['receipts'][$id]['status']]);

            return ['receipt_handle' => $id, 'status' => $state['receipts'][$id]['status'], 'duplicate' => false];
        });
    }

    /** Exercise the same authenticated receiver with an explicitly synthetic host-generated event. */
    public function simulateCallback(string $orderId, string $status): array
    {
        if (! in_array($status, ['Shipped', 'Delivered'], true)) {
            throw new InvalidArgumentException('callback_fixture_status');
        }
        $fixture = $this->store->transaction(function (array &$state) use ($orderId, $status): array {
            $order = $state['orders'][$orderId] ?? throw new InvalidArgumentException('order_not_found');
            $endpoint = array_find($state['endpoints'], static fn (array $value): bool => $value['lineage'] === $order['lineage']) ?? throw new InvalidArgumentException('webhook_endpoint_required');
            $reference = array_find($order['references'], static fn (array $value): bool => $value['reference'] !== null) ?? throw new InvalidArgumentException('accepted_reference_required');
            $raw = $this->store->open($reference['reference'])['value'];

            $profile = $endpoint['profile'] ?? $this->inboundProfile($state['packages'][$order['execution_revision']]);
            $eventId = 'workbench-'.$orderId.'-'.strtolower($status);
            $payload = ['orderId' => $raw, 'order_id' => $raw, 'reference' => $raw, 'eventId' => $eventId, 'event_id' => $eventId, 'status' => strtolower($status), 'rxStatus' => $status, 'rxStatusDateTime' => $order['created_at'], 'occurred_at' => $order['created_at'], 'trackingNumber' => 'SYNTHETIC-'.substr($orderId, 0, 8), 'tracking_number' => 'SYNTHETIC-'.substr($orderId, 0, 8), 'shipCarrier' => 'Local synthetic vendor', 'carrier' => 'Local synthetic vendor'];
            InboundProfile::set($payload, $profile['reference_field'], $raw);
            InboundProfile::set($payload, $profile['event_id_field'], $eventId);
            foreach ($this->callbackAccounts($profile, $order['config']) as $field => $value) {
                InboundProfile::set($payload, $field, $value);
            }

            return ['endpoint' => $endpoint['uuid'], 'credentials' => $this->store->open($endpoint['credentials']), 'payload' => $payload];
        });

        return $this->receive($fixture['endpoint'], $fixture['credentials']['username'], $fixture['credentials']['password'], json_encode($fixture['payload'], JSON_THROW_ON_ERROR));
    }

    private function applyProposal(array &$order, array $proposal, array $payload, array $profile): bool
    {
        if (($proposal['command'] ?? null) !== 'normalized_event' || ! is_array($proposal['event'] ?? null)) {
            return false;
        }
        $proposal = $proposal['event'];
        $owned = false;
        foreach ($order['references'] as $reference) {
            if ($reference['reference'] !== null && is_string($proposal['external_order_reference'] ?? null) && hash_equals($this->store->open($reference['reference'])['value'], $proposal['external_order_reference'])) {
                $owned = true;
            }
        }
        if (! $owned) {
            return false;
        }
        $status = (string) ($proposal['normalized_status'] ?? '');
        $eventId = $proposal['event_id'] ?? null;
        $sourceEvent = InboundProfile::value($payload, $profile['event_id_field']);
        if (is_int($sourceEvent)) {
            $sourceEvent = (string) $sourceEvent;
        }
        if (($eventId !== null && $eventId !== '' && (! is_string($eventId) || ! is_string($sourceEvent) || ! hash_equals($sourceEvent, $eventId)))
            || ! in_array($status, ['received', 'queued', 'in_progress', 'ready_for_pickup', 'shipped', 'delivered'], true)) {
            return false;
        }
        $sourceFingerprint = hash('sha256', self::canonical($payload));
        $eventId = is_string($sourceEvent) && $sourceEvent !== '' ? 'event:'.$sourceEvent : 'body:'.$sourceFingerprint;
        if (isset($order['event_fingerprints'][$eventId])) {
            return hash_equals($order['event_fingerprints'][$eventId], $sourceFingerprint);
        }
        if (in_array($eventId, $order['event_ids'], true)) {
            return true;
        }
        $order['event_ids'][] = $eventId;
        $order['event_fingerprints'][$eventId] = $sourceFingerprint;
        $delivered = $status === 'delivered';
        $target = match ($status) {
            'received', 'queued' => 'accepted', 'in_progress' => 'in_progress', 'ready_for_pickup', 'shipped' => 'ready', 'delivered' => 'completed'
        };
        $rank = ['submitted_to_pos' => 0, 'accepted' => 1, 'in_progress' => 2, 'ready' => 3, 'completed' => 4];
        if (($rank[$target] ?? 0) > ($rank[$order['status']] ?? 0)) {
            $order['status'] = $target;
        }
        if (! in_array($status, ['shipped', 'delivered'], true)) {
            return true;
        }
        $tracking = $proposal['tracking_number'] ?? null;
        if (! is_string($tracking) || trim($tracking) === '') {
            return true;
        }
        $trackingHash = hash('sha256', strtolower(trim($tracking)));
        $parcelId = $order['fulfillment_tracking'][$trackingHash] ?? null;
        $parcelIndex = $parcelId === null ? null : array_find_key($order['fulfillments'], static fn (array $value): bool => $value['uuid'] === $parcelId);
        if ($parcelIndex === null) {
            $carrier = $proposal['carrier'] ?? null;
            $parcelId = WorkbenchState::uuid();
            $order['fulfillments'][] = ['uuid' => $parcelId, 'status' => 'in_transit', 'carrier' => is_string($carrier) ? substr($carrier, 0, 120) : 'Synthetic vendor', 'tracking_present' => true, 'shipped' => true, 'delivered' => false];
            $parcelIndex = array_key_last($order['fulfillments']);
            $order['fulfillment_tracking'][$trackingHash] = $parcelId;
        }
        if ($delivered) {
            $order['fulfillments'][$parcelIndex]['status'] = 'delivered';
            $order['fulfillments'][$parcelIndex]['delivered'] = true;
        }

        return true;
    }

    private function findCallbackOrder(array $state, array $endpoint, array $payload): string
    {
        $profile = $endpoint['profile'] ?? $this->inboundProfile($state['packages'][$state['connections'][$endpoint['current_connection']]['execution_revision']]);
        $reference = InboundProfile::value($payload, $profile['reference_field']);
        if (is_int($reference)) {
            $reference = (string) $reference;
        }
        if (! is_string($reference)) {
            throw new InvalidArgumentException('callback_reference_missing');
        }
        foreach ($state['orders'] as $order) {
            if ($order['lineage'] !== $endpoint['lineage'] || $order['delivery_status'] !== 'succeeded' || ($order['result']['kind'] ?? null) !== 'remote_acceptance') {
                continue;
            }
            foreach ($order['references'] as $known) {
                if ($known['reference'] !== null && hash_equals($this->store->open($known['reference'])['value'], $reference)) {
                    if (method_exists($this->runtime, 'referenceIsAmbiguous') && $this->runtime->referenceIsAmbiguous($known['handle'])) {
                        throw new InvalidArgumentException('callback_reference_ambiguous');
                    }

                    return $order['uuid'];
                }
            }
        }

        throw new InvalidArgumentException('callback_reference_not_owned');
    }

    private function inboundProfile(array $package): array
    {
        $fixture = $this->fixtures($package)['defaults'] ?? [];
        $profile = $fixture['inbound_profile'] ?? ['version' => 1, 'authentication' => 'basic', 'label' => 'Local callback fixture', 'reference_field' => 'orderId', 'event_id_field' => 'eventId', 'account_fields' => $fixture['callback_account_fields'] ?? [], 'package_ids' => [$package['manifest']['package_id']]];

        return InboundProfile::validate($profile, $package['manifest']['package_id']);
    }

    private function callbackAccounts(array $profile, array $configuration): array
    {
        $result = [];
        foreach ($profile['account_fields'] as $field => $configKey) {
            $value = InboundProfile::value($configuration, $configKey);
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException('callback_account_configuration_missing');
            }
            $result[$field] = (string) $value;
        }

        return $result;
    }

    private function invoke(array $state, array $connection, string $operation, array $input, array $resources = [], ?array $credentials = null, array $scope = []): array
    {
        $deployment = $state['packages'][$connection['execution_revision']];
        $fixture = $this->fixtures($deployment)['defaults'] ?? [];
        if (isset($fixture['catalog'])) {
            $resources = ['catalog' => ['records' => $fixture['catalog']], ...$resources];
        }
        foreach ($connection['resource_fixtures'] ?? [] as $type => $records) {
            $resources[$type] = ['records' => array_values(array_map(static fn (array $record): array => $record['values'], $records))];
        }
        if (! in_array($operation, array_column($deployment['manifest']['operations'], 'key'), true)) {
            return (new Result('unsupported_operation', 'operation.unsupported'))->jsonSerialize();
        }
        $scope = [...['connection_id' => $connection['uuid'], 'organization_id' => $connection['organization_id'], 'location_id' => $connection['location_id'], 'environment' => 'sandbox', 'configuration_revision' => $connection['configuration_revision'], 'credential_revision' => $connection['credential_revision']], ...$scope];
        $result = $this->runtime->invoke($deployment, $operation, $input, $connection['config'], $credentials ?? $this->store->open($connection['credentials']), $resources, $scope);

        return Result::fromArray($result)->jsonSerialize();
    }

    public function openSession(string $connectionId, string $surface, string $browserSession, string $hostOrigin, ?string $itemId = null, ?string $orderId = null): array
    {
        if (! preg_match('~\Ahttp://127\.0\.0\.1:[1-9][0-9]{0,4}\z~D', $hostOrigin)) {
            throw new InvalidArgumentException('workbench_origin_required');
        }

        return $this->store->transaction(function (array &$state) use ($connectionId, $surface, $browserSession, $hostOrigin, $itemId, $orderId): array {
            $connection = $state['connections'][$connectionId] ?? throw new InvalidArgumentException('connection_not_found');
            $order = $orderId !== null ? ($state['orders'][$orderId] ?? throw new InvalidArgumentException('order_not_found')) : null;
            if (str_contains($surface, 'order.details')) {
                if ($order === null || $order['connection_id'] !== $connectionId) {
                    throw new InvalidArgumentException('order_connection_mismatch');
                }
            } elseif ($surface === 'catalog.item.mapping') {
                if ($connection['status'] !== 'active' || $itemId === null) {
                    throw new InvalidArgumentException('active_mapping_connection_required');
                }
            } elseif ($surface !== 'connection.setup') {
                throw new InvalidArgumentException('ui_surface_unsupported');
            }
            $execution = $order['execution_revision'] ?? $connection['execution_revision'];
            $deployment = $state['packages'][$execution];
            if (! in_array($surface, $deployment['manifest']['ui']['surfaces'] ?? [], true)) {
                throw new InvalidArgumentException('package_surface_not_declared');
            }
            $ui = StaticUi::inspect($deployment['package_root'], $deployment['manifest']);
            $id = WorkbenchState::uuid();
            $nonce = bin2hex(random_bytes(32));
            $revision = $this->bindingRevision($connection, $surface, $order);
            $port = parse_url($hostOrigin, PHP_URL_PORT);
            $packageOrigin = 'http://pkg-'.$execution.'.localhost:'.$port;
            $session = ['uuid' => $id, 'nonce_hash' => hash('sha256', $nonce), 'browser_hash' => hash('sha256', $browserSession), 'host_origin' => $hostOrigin, 'connection_id' => $connectionId, 'execution_revision' => $execution, 'expected_revision' => $revision, 'surface' => $surface, 'catalog_item_id' => $itemId, 'order_id' => $orderId, 'expires' => time() + 300, 'requests' => []];
            $state['sessions'][$id] = $session;
            $permissions = HostContract::surface($surface);

            return ['session_id' => $id, 'nonce' => $nonce, 'execution_revision' => $execution, 'expected_revision' => $revision, 'package_origin' => $packageOrigin, 'asset_url' => $packageOrigin.'/assets/'.$ui['sha256'].'/'.$deployment['manifest']['ui']['entrypoint'], 'host_origin' => $hostOrigin, 'expires_at' => gmdate('c', $session['expires']), 'surface' => $surface, 'bridge_url' => '/api/bridge/'.$id, 'queries' => $permissions['queries'], 'actions' => $permissions['actions'], 'sensitive_operations_available' => false,
                'context' => array_filter(['sessionId' => $id, 'executionRevision' => $execution, 'expectedRevision' => $revision, 'actorId' => 'actor_'.hash('sha256', $browserSession), 'organizationId' => $connection['organization_id'], 'locationId' => $connection['location_id'], 'connectionId' => $connectionId, 'environment' => 'sandbox', 'surface' => $surface, 'catalogItemId' => $itemId, 'orderId' => $orderId], static fn (mixed $value): bool => $value !== null)];
        });
    }

    public function bridge(string $id, array $message, string $browserSession, string $hostOrigin): array
    {
        $required = ['version', 'id', 'nonce', 'session_id', 'execution_revision', 'expected_revision', 'kind', 'operation', 'arguments'];
        if (array_diff($required, array_keys($message)) !== [] || array_diff(array_keys($message), $required) !== [] || ($message['version'] ?? null) !== '1' || ! is_string($message['id']) || ! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $message['id']) || ! is_array($message['arguments']) || ! JsonValue::object($message['arguments']) || ! in_array($message['kind'], ['query', 'action'], true) || strlen(json_encode($message, JSON_THROW_ON_ERROR)) > 65536) {
            throw new InvalidArgumentException('bridge_request_invalid');
        }
        $this->safeKeys($message);

        return $this->store->transaction(function (array &$state) use ($id, $message, $browserSession, $hostOrigin): array {
            $session = &$state['sessions'][$id];
            if (! is_array($session) || $session['expires'] <= time() || ! hash_equals($session['browser_hash'], hash('sha256', $browserSession)) || ! hash_equals($session['nonce_hash'], hash('sha256', (string) $message['nonce'])) || $session['host_origin'] !== $hostOrigin || $message['session_id'] !== $id || $message['execution_revision'] !== $session['execution_revision'] || $message['expected_revision'] !== $session['expected_revision']) {
                throw new RuntimeException('bridge_session_denied');
            }
            $connection = &$state['connections'][$session['connection_id']];
            $order = $session['order_id'] !== null ? $state['orders'][$session['order_id']] : null;
            if ($this->bindingRevision($connection, $session['surface'], $order) !== $session['expected_revision'] || ($session['surface'] === 'catalog.item.mapping' && $connection['status'] !== 'active') || $connection['status'] === 'revoked') {
                throw new RuntimeException('bridge_session_stale');
            }
            $permissions = HostContract::surface($session['surface']);
            if (! in_array($message['operation'], $permissions[$message['kind'] === 'query' ? 'queries' : 'actions'], true)) {
                throw new RuntimeException('bridge_operation_denied');
            }
            $fingerprint = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
            if (isset($session['requests'][$message['id']]) && $session['requests'][$message['id']] !== $fingerprint) {
                throw new RuntimeException('bridge_request_replay_conflict');
            }
            if (count($session['requests']) >= 1000) {
                throw new RuntimeException('bridge_request_limit');
            }
            $session['requests'][$message['id']] = $fingerprint;
            $arguments = $message['arguments'];
            $operation = $message['operation'];
            if ($message['kind'] === 'query' && $arguments !== []) {
                throw new InvalidArgumentException('bridge_query_arguments_forbidden');
            }
            if ($operation === 'config.save') {
                if (array_keys($arguments) !== ['config'] || ! is_array($arguments['config'])) {
                    throw new InvalidArgumentException('bridge_config_arguments');
                }
                $this->saveConnection($state, $connection, ['expected_version' => $connection['version'], 'config' => $arguments['config']]);

                return ['host_reload_required' => true];
            }
            if ($operation === 'mapping.save') {
                if (array_diff(array_keys($arguments), ['values', 'expected_revision']) !== []) {
                    throw new InvalidArgumentException('bridge_mapping_arguments');
                }

                return $this->saveMapping($state, $connection, [...$arguments, 'catalog_item_id' => $session['catalog_item_id']]);
            }
            $deployment = $state['packages'][$session['execution_revision']];

            $projection = match ($operation) {
                'context.read' => ['package_id' => $deployment['manifest']['package_id'], 'package_version' => $deployment['manifest']['version'], 'execution_revision' => $session['execution_revision'], 'environment' => 'sandbox', 'deployment_environment' => 'local', 'connection_status' => $connection['status'], 'setup_test_current' => $this->fresh($connection, 'test'), 'surface' => $session['surface'], 'catalog_item_uuid' => $session['catalog_item_id'], 'order_uuid' => $session['order_id'], 'sensitive_operations_available' => false],
                'config.read' => ['editable' => true, 'schema' => $this->safeConfigurationSchema($deployment), 'values' => array_filter(array_intersect_key($connection['config'], $this->safeConfigurationSchema($deployment)['properties']), is_scalar(...)), 'credentials_host_owned' => true],
                'mapping.read' => ['schema' => $this->schema($deployment, 'order_mapping', true), 'values' => $connection['mappings'][$session['catalog_item_id']]['values'] ?? [], 'revision' => $connection['mappings'][$session['catalog_item_id']]['revision'] ?? 0, 'status' => $connection['mappings'][$session['catalog_item_id']]['status'] ?? null],
                'order.read' => $this->orderProjection($order),
                'fulfillments.read' => ['fulfillments' => $order['fulfillments']],
                'reference.timezones' => ['options' => HostContract::timezones()],
                default => throw new InvalidArgumentException('bridge_operation_unsupported'),
            };
            HostContract::assertProjection($operation, $projection);

            return $projection;
        });
    }

    public function asset(string $execution, string $digest, string $relative): array
    {
        return $this->store->transaction(function (array &$state) use ($execution, $digest, $relative): array {
            $deployment = $state['packages'][$execution] ?? throw new InvalidArgumentException('asset_not_found');
            $ui = StaticUi::inspect($deployment['package_root'], $deployment['manifest']);
            if (! hash_equals($ui['sha256'], $digest) || ! StaticUi::isPath($relative) || ! isset($ui['files'][substr($relative, 3)])) {
                throw new InvalidArgumentException('asset_not_found');
            }
            $body = file_get_contents($deployment['package_root'].'/'.$relative);
            if (! is_string($body) || ! hash_equals($ui['files'][substr($relative, 3)], hash('sha256', $body))) {
                throw new RuntimeException('asset_changed');
            }

            return ['body' => $body, 'type' => match (pathinfo($relative, PATHINFO_EXTENSION)) {
                'html' => 'text/html; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', default => throw new InvalidArgumentException('asset_not_found')
            }];
        });
    }

    private function connectionSummary(array $state, array $connection): array
    {
        $package = $state['packages'][$connection['execution_revision']];
        $endpoints = array_values(array_map(static fn (array $endpoint): array => ['uuid' => $endpoint['uuid'], 'path' => '/pos-webhooks/'.$endpoint['uuid']], array_filter($state['endpoints'], static fn (array $endpoint): bool => $endpoint['lineage'] === $connection['lineage'])));

        return ['uuid' => $connection['uuid'], 'execution_revision' => $connection['execution_revision'], 'package_id' => $package['manifest']['package_id'], 'package_version' => $package['manifest']['version'], 'display_name' => $package['manifest']['display_name'], 'status' => $connection['status'], 'version' => $connection['version'], 'organization_id' => $connection['organization_id'], 'location_id' => $connection['location_id'], 'environment' => 'sandbox', 'configuration_revision' => $connection['configuration_revision'], 'credential_revision' => $connection['credential_revision'], 'config' => array_intersect_key($connection['config'], $this->safeConfigurationSchema($package)['properties']), 'credentials_saved' => true, 'health' => $connection['health'], 'test' => $connection['test'], 'health_current' => $this->fresh($connection, 'health'), 'test_current' => $this->fresh($connection, 'test'), 'health_interval_minutes' => $connection['health_interval_minutes'], 'mappings' => $connection['mappings'], 'resource_fixtures' => $connection['resource_fixtures'] ?? [], 'sender_overrides' => array_map(fn (array $override): array => $this->overrideSummary($connection, $override), $connection['overrides']), 'webhooks' => $endpoints, 'upgraded_from' => $connection['upgraded_from'] ?? null];
    }

    private function packageSummary(array $package): array
    {
        return ['execution_revision' => $package['execution_revision'], 'manifest' => $package['manifest'], 'source_sha256' => $package['source_sha256'], 'status' => $package['status'] ?? 'development_ready', 'configuration_schema' => $this->schema($package, 'configuration_schema'), 'credential_schema' => $this->schema($package, 'credential_schema'), 'resource_schemas' => array_map(fn (string $name): ?array => $this->schema($package, $name, true), array_combine(array_keys($package['manifest']['resource_schemas'] ?? []), array_keys($package['manifest']['resource_schemas'] ?? [])) ?: []), 'fixtures' => $this->fixtures($package)];
    }

    private function fixtures(array $package): array
    {
        $fixtures = [];
        foreach ($this->fixtureCatalog as $fixture) {
            if ($fixture['package_id'] === $package['manifest']['package_id']) {
                $fixtures['defaults'] = $fixture;
                if (isset($fixture['order'])) {
                    $fixtures['order'] = $fixture['order'];
                }
                break;
            }
        }
        foreach (['defaults', 'order'] as $name) {
            $file = $package['package_root'].'/fixtures/'.$name.'.json';
            if (is_file($file) && ! is_link($file) && filesize($file) <= 65536) {
                $fixtures[$name] = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            }
        }

        return $fixtures;
    }

    private function orderSummary(array $state, array $order): array
    {
        $package = $state['packages'][$order['execution_revision']];

        return [...$this->orderProjection($order), 'connection_id' => $order['connection_id'], 'sender_id' => $order['sender_id'], 'execution_revision' => $order['execution_revision'], 'package_id' => $package['manifest']['package_id'], 'package_version' => $package['manifest']['version'], 'credential_revision' => $order['credential_revision'], 'mapping_revision' => $order['mapping_revision'], 'sender_override' => $order['sender_override'], 'result' => $order['result'], 'action_revision' => $order['action_revision'] ?? 0, 'actions' => array_values(array_map($this->actionSummary(...), $order['actions'] ?? []))];
    }

    private function resultDeliveryStatus(string $kind): string
    {
        $statuses = HostContract::read()['runtime']['result_statuses'] ?? [];

        return $statuses[$kind] ?? throw new InvalidArgumentException('host_result_status_contract_unavailable');
    }

    private function orderProjection(array $order): array
    {
        $status = $order['status'];
        $deliveryStatus = $order['delivery_status'];
        $legacyOutcomeAsStatus = array_key_exists($deliveryStatus, HostContract::read()['runtime']['result_statuses']);
        if (($order['result']['kind'] ?? null) !== 'remote_acceptance' && ($status === 'unknown' || ($status === 'rejected' && $legacyOutcomeAsStatus))) {
            $status = 'queued_for_pos';
        }
        if ($legacyOutcomeAsStatus) {
            $deliveryStatus = $this->resultDeliveryStatus($deliveryStatus);
        }

        return ['uuid' => $order['uuid'], 'status' => $status, 'route_version' => $order['route_version'], 'delivery_status' => $deliveryStatus, 'pos_order_references' => array_map(static fn (array $reference): array => ['handle' => $reference['handle'], 'status' => 'accepted', 'present' => true, 'fingerprint' => $reference['fingerprint']], $order['references']), 'fulfillments' => $order['fulfillments']];
    }

    private function schema(array $package, string $name, bool $resource = false): ?array
    {
        $path = $resource ? ($package['manifest']['resource_schemas'][$name] ?? null) : ($package['manifest'][$name] ?? null);
        if (! is_string($path)) {
            return null;
        }
        Manifest::path($path);
        $file = $package['package_root'].'/'.$path;
        if (! is_file($file) || is_link($file) || filesize($file) > 262144) {
            throw new InvalidArgumentException('schema_unavailable');
        }

        return json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    }

    private function validate(array $package, string $name, array $values): void
    {
        $schema = $this->schema($package, $name);
        if ($schema === null) {
            if ($values !== []) {
                throw new InvalidArgumentException('schema_not_declared');
            }

            return;
        }
        $definition = json_decode((string) file_get_contents($package['package_root'].'/'.$package['manifest'][$name]));
        ConfigurationSchema::validateValues($definition, $values, $name === 'credential_schema');
    }

    private function validateResource(array $package, string $name, array $values): void
    {
        $schema = $this->schema($package, $name, true) ?? throw new InvalidArgumentException('resource_schema_not_declared');
        $definition = json_decode((string) file_get_contents($package['package_root'].'/'.$package['manifest']['resource_schemas'][$name]));
        MappingSchemaPolicy::assertSafe($definition, array_keys($this->schema($package, 'credential_schema')['properties'] ?? []), $name);
        ResourceSchema::validateValues($definition, $values);
    }

    private function safeConfigurationSchema(array $package): array
    {
        $schema = $this->schema($package, 'configuration_schema') ?? ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false];
        $credentialKeys = array_keys($this->schema($package, 'credential_schema')['properties'] ?? []);
        $schema['properties'] = array_filter($schema['properties'], static fn (string $name): bool => ! in_array($name, $credentialKeys, true) && ! preg_match('/(?:^|_)(?:password|secret|token|credentials?|api_?key|private_?key|authorization|username|auth)(?:_|$)/i', $name), ARRAY_FILTER_USE_KEY);
        $schema['required'] = array_values(array_intersect($schema['required'], array_keys($schema['properties'])));

        return $schema;
    }

    private function fresh(array $connection, string $key): bool
    {
        $evidence = $connection[$key];

        return is_array($evidence) && (! $this->liveVerificationRequired($connection) || ($evidence['verification']['verified'] ?? false) === true) && $evidence['successful'] && $evidence['configuration_revision'] === $connection['configuration_revision'] && $evidence['credential_revision'] === $connection['credential_revision'] && ($evidence['transport_binding'] ?? null) === $this->transportBinding($connection) && $evidence['checked_at'] > time() - $connection['health_interval_minutes'] * 60;
    }

    private function transportBinding(array $connection): string
    {
        return method_exists($this->runtime, 'transportBinding') ? $this->runtime->transportBinding($connection['package_id'] ?? '') : 'injected-fixture-runtime';
    }

    private function credentialProof(array $state, array $connection, array $credentials, array $scope = []): array
    {
        $offline = ['accepted' => ! $this->liveVerificationRequired($connection), 'verified' => false, 'simulated' => true, 'synthetic' => true, 'provenance' => 'offline_fixture'];
        if (! method_exists($this->runtime, 'verifyCredentials')) {
            return $offline;
        }
        try {
            $proof = $this->runtime->verifyCredentials($state['packages'][$connection['execution_revision']], $connection['config'], $credentials,
                [...['connection_id' => $connection['uuid'], 'organization_id' => $connection['organization_id'], 'location_id' => $connection['location_id'], 'environment' => 'sandbox', 'configuration_revision' => $connection['configuration_revision'], 'credential_revision' => $connection['credential_revision']], ...$scope]);
            if (($proof['verified'] ?? false) !== true) {
                return $offline;
            }
            foreach (['profile_sha256', 'principal_fingerprint', 'account_fingerprint'] as $field) {
                if (! is_string($proof[$field] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $proof[$field])) {
                    return [...$offline, 'accepted' => false];
                }
            }
            if (! is_bool($proof['synthetic'] ?? null)) {
                return [...$offline, 'accepted' => false];
            }

            return ['accepted' => true, 'verified' => true, 'simulated' => $proof['synthetic'], 'synthetic' => $proof['synthetic'], 'provenance' => $proof['synthetic'] ? 'managed_profile_mock' : 'managed_profile_http',
                'profile_sha256' => $proof['profile_sha256'], 'principal_fingerprint' => $proof['principal_fingerprint'], 'account_fingerprint' => $proof['account_fingerprint']];
        } catch (Throwable) {
            return [...$offline, 'accepted' => false];
        }
    }

    private function assertAccountAvailable(array $state, array $connection, array $proof): void
    {
        $ownerId = $state['account_bindings'][$proof['account_fingerprint']] ?? null;
        if ($ownerId === null || $ownerId === $connection['uuid']) {
            return;
        }
        $owner = $state['connections'][$ownerId] ?? null;
        if (! is_array($owner) || ($connection['upgraded_from'] ?? null) !== $ownerId
            || $owner['lineage'] !== $connection['lineage'] || $owner['status'] !== 'active'
            || $owner['organization_id'] !== $connection['organization_id'] || $owner['location_id'] !== $connection['location_id']
            || $owner['environment'] !== $connection['environment'] || ($owner['package_id'] ?? null) !== ($connection['package_id'] ?? null)
            || $owner['execution_revision'] === $connection['execution_revision']) {
            throw new InvalidArgumentException('connection_account_already_owned');
        }
    }

    private function liveVerificationRequired(array $connection): bool
    {
        $mode = method_exists($this->runtime, 'networkMode') ? $this->runtime->networkMode() : [];

        return ($mode['live_sandbox_enabled'] ?? false) === true && ($mode['selected_package_id'] ?? null) === ($connection['package_id'] ?? null);
    }

    private function overrideState(array $override): string
    {
        return $override['state'] ?? ($override['revoked'] ? 'revoked' : ($override['enabled'] ? 'enabled' : 'draft'));
    }

    private function overrideTestCurrent(array $connection, array $override): bool
    {
        return (! $this->liveVerificationRequired($connection) || ($override['verification']['verified'] ?? false) === true)
            && ($override['tested_revision'] ?? null) === $override['revision']
            && ($override['tested_configuration_revision'] ?? null) === $connection['configuration_revision']
            && ($override['tested_execution_revision'] ?? null) === $connection['execution_revision']
            && ($override['tested_transport_binding'] ?? null) === $this->transportBinding($connection);
    }

    private function overrideSummary(array $connection, array $override): array
    {
        return ['revision' => $override['revision'], 'enabled' => $override['enabled'], 'state' => $this->overrideState($override), 'removed' => $this->overrideState($override) === 'removed', 'tested' => $this->overrideTestCurrent($connection, $override), 'revoked' => $override['revoked'],
            'history' => array_values(array_map(static fn (array $record): array => ['revision' => $record['revision'], 'revoked' => ($record['revoked'] ?? false) === true], $override['history'] ?? []))];
    }

    private function assertOrderCredentialsAvailable(array $connection, array $order): void
    {
        if ($connection['status'] === 'revoked') {
            throw new InvalidArgumentException('connection_revoked');
        }
        if (! $order['sender_override']) {
            return;
        }
        $override = $connection['overrides'][$order['sender_id']] ?? null;
        $record = is_array($override) && $override['revision'] === $order['credential_revision'] ? $override : array_find($override['history'] ?? [], static fn (array $value): bool => ($value['revision'] ?? null) === $order['credential_revision']);
        if ($record === null || ($record['revoked'] ?? false) === true) {
            throw new InvalidArgumentException('sender_credentials_pin_revoked');
        }
    }

    private function bindingRevision(array $connection, string $surface, ?array $order): string
    {
        return $order !== null ? $order['configuration_revision'].':'.$order['credential_revision'] : $connection['configuration_revision'].':'.$connection['credential_revision'];
    }

    private function scope(array $connection, array $input): void
    {
        foreach (['organization_id', 'location_id', 'environment'] as $key) {
            if (isset($input[$key]) && $input[$key] !== $connection[$key]) {
                throw new InvalidArgumentException('connection_scope_mismatch');
            }
        }
    }

    private function invalidateSessions(array &$state, string $connectionId): void
    {
        foreach ($state['sessions'] as $id => $session) {
            if ($session['connection_id'] === $connectionId) {
                unset($state['sessions'][$id]);
            }
        }
    }

    private function event(array &$state, string $type, array $data): void
    {
        $state['events'][] = ['type' => $type, 'data' => $data, 'at' => gmdate('c')];
        $state['events'] = array_slice($state['events'], -200);
    }

    private function safeKeys(mixed $value, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('bridge_json_depth');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
                    throw new InvalidArgumentException('bridge_unsafe_key');
                }
                $this->safeKeys($child, $depth + 1);
            }
        }
    }

    private static function safeResult(array $result): array
    {
        return ['kind' => $result['kind'], 'reason_code' => $result['reason_code'], 'field_errors' => array_map(static fn (array $error): array => ['path' => $error['path'], 'reason_code' => $error['reason_code']], $result['field_errors']), 'recovery' => array_intersect_key($result['recovery'], array_flip(['action', 'resubmit_allowed', 'retry_after_seconds']))];
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return json_encode(array_map(static fn (mixed $child): string => self::canonical($child), $value), JSON_THROW_ON_ERROR);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function text(array $input, string $key): string
    {
        if (! is_string($input[$key] ?? null) || $input[$key] === '' || strlen($input[$key]) > 240) {
            throw new InvalidArgumentException('workbench_field_required:'.$key);
        }

        return $input[$key];
    }

    private static function object(array $input, string $key): array
    {
        $value = $input[$key] ?? [];
        if (! is_array($value) || ! JsonValue::object($value)) {
            throw new InvalidArgumentException('workbench_object_required:'.$key);
        }

        return $value;
    }
}
