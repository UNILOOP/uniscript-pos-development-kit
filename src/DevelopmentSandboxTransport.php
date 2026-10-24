<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Explicit trusted-operator Sandbox egress. Package code never supplies URLs, headers or auth. */
final class DevelopmentSandboxTransport
{
    private readonly PDO $db;

    private readonly WorkbenchState $vault;

    private array $evidence = ['external_references' => [], 'network_access' => false];

    private array $secretValues = [];

    public function __construct(private readonly string $database, private readonly array $options, private readonly mixed $resolver = null, private readonly mixed $sender = null)
    {
        if (($options['enabled'] ?? false) !== true || ! is_string($options['package_id'] ?? null) || ! preg_match('/\A[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*\z/D', $options['package_id']) || ! is_array($options['profiles'] ?? null)) {
            throw new InvalidArgumentException('sandbox_transport_explicit_configuration_required');
        }
        if (($options['test_loopback'] ?? false) && (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('POS_KIT_TRANSPORT_LOOPBACK_TEST') !== '1')) {
            throw new InvalidArgumentException('sandbox_loopback_test_guard');
        }
        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), 0700, true);
        }
        $this->db = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        chmod($database, 0600);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sandbox_attempts (scope TEXT NOT NULL, profile TEXT NOT NULL, operation TEXT NOT NULL, logical_key TEXT NOT NULL, fingerprint TEXT NOT NULL, status TEXT NOT NULL, response TEXT, handle TEXT, reference TEXT, PRIMARY KEY(scope,profile,operation,logical_key))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sandbox_reference_owners (account_scope TEXT NOT NULL, reference_sha256 TEXT NOT NULL, connection_scope TEXT NOT NULL, owner_profile TEXT NOT NULL, owner_operation TEXT NOT NULL, owner_logical_key TEXT NOT NULL, handle TEXT NOT NULL UNIQUE, reference TEXT NOT NULL, status TEXT NOT NULL, PRIMARY KEY(account_scope,reference_sha256))');
        $this->vault = new WorkbenchState(dirname($database).'/sandbox-vault');
    }

    public static function fromEnvironment(string $database): ?self
    {
        if (getenv('POS_KIT_LIVE_SANDBOX') !== '1') {
            return null;
        }
        $path = getenv('POS_KIT_SANDBOX_PROFILES') ?: '';
        if (! is_file($path) || is_link($path) || filesize($path) > 262144) {
            throw new InvalidArgumentException('sandbox_trusted_profile_file_required');
        }
        $data = JsonDocument::decode(file_get_contents($path), true);
        if (($data['version'] ?? null) !== 1 || ($data['environment'] ?? null) !== 'sandbox' || ! is_array($data['profiles'] ?? null) || array_diff(array_keys($data), ['version', 'environment', 'profiles', 'verification_profile', 'verification_profiles']) !== []) {
            throw new InvalidArgumentException('sandbox_profile_registry_invalid');
        }

        return new self($database, ['enabled' => true, 'package_id' => getenv('POS_KIT_LIVE_SANDBOX_PACKAGE') ?: '', 'profiles' => $data['profiles'], 'verification_profile' => $data['verification_profile'] ?? null, 'verification_profiles' => $data['verification_profiles'] ?? [], 'source_file' => realpath($path)]);
    }

    public function packageId(): string
    {
        return $this->options['package_id'];
    }

    public function configurationHash(): string
    {
        return hash('sha256', self::canonical(['mode' => 'live_sandbox', 'package_id' => $this->options['package_id'], 'profiles' => $this->options['profiles'], 'verification_profile' => $this->options['verification_profile'] ?? null, 'verification_profiles' => $this->options['verification_profiles'] ?? []]));
    }

    public function assertHostOwned(string $packageRoot): void
    {
        $root = realpath($packageRoot);
        if (isset($this->options['source_file']) && $root !== false && str_starts_with($this->options['source_file'], rtrim($root, '/').'/')) {
            throw new RuntimeException('sandbox_profiles_must_be_host_owned');
        }
    }

    public function verificationDescriptor(): ?array
    {
        $key = $this->options['verification_profile'] ?? null;
        $descriptor = is_string($key) ? ($this->options['verification_profiles'][$key] ?? null) : null;

        return is_array($descriptor) ? [...$descriptor, 'key' => $key] : null;
    }

    public function profile(string $name, string $operation): array
    {
        $profile = $this->options['profiles'][$name] ?? throw new RuntimeException('sandbox_profile_not_registered');
        $this->assertProfile($profile, ['package_id' => $this->packageId(), 'operation' => $operation]);

        return $profile;
    }

    /** Host-only verification response; never return its raw body through package services or UI. */
    public function verificationRequest(array $envelope, array $descriptor, array $configuration, array $credentials, string $packageRoot, array $headers = []): array
    {
        Invocation::fromArray($envelope);
        if ($descriptor !== $this->verificationDescriptor() || $envelope['package_id'] !== $this->packageId() || $envelope['environment'] !== 'sandbox' || ($envelope['deployment_environment'] ?? null) !== 'local' || $envelope['operation'] !== 'connection.test') {
            throw new RuntimeException('sandbox_verification_scope_denied');
        }
        $this->assertHostOwned($packageRoot);
        $profile = $this->profile($descriptor['transport_profile'] ?? '', 'connection.test');
        if ($profile['method'] !== 'GET' || ($profile['success_policy'] ?? null) !== 'read_only_json' || str_contains($profile['path'], '{') || array_diff(array_keys($headers), ['X-UniScript-Challenge']) !== [] || (isset($headers['X-UniScript-Challenge']) && (! is_string($headers['X-UniScript-Challenge']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $headers['X-UniScript-Challenge'])))) {
            throw new RuntimeException('sandbox_verification_read_profile_required');
        }
        $this->evidence = ['external_references' => [], 'network_access' => false];
        $requestHeaders = ['Accept' => 'application/json', 'User-Agent' => 'UniScript-POS-Developer-Kit-Sandbox/1', 'X-Request-ID' => $envelope['invocation_id'], 'X-Correlation-ID' => $envelope['correlation_id'], ...$headers];
        foreach ($profile['config_headers'] as $header => $field) {
            $requestHeaders[$header] = $this->scalar(self::value($configuration, $field), 'sandbox_configuration_header_invalid', 240);
        }
        $deadline = gmdate('Y-m-d\TH:i:s\Z', min(time() + 10, strtotime($envelope['deadline'])));
        $requestHeaders += $this->authentication($profile, $credentials, $deadline);

        return $this->send($this->endpoint(rtrim($profile['base_url'], '/').$profile['path']), 'GET', $requestHeaders, null, $deadline, 16384);
    }

    public function evidence(): array
    {
        return $this->evidence;
    }

    public function referenceStatus(array $envelope, string $handle, array $configuration): array
    {
        if (($envelope['package_id'] ?? null) !== $this->options['package_id'] || ($envelope['environment'] ?? null) !== 'sandbox') {
            return ['status' => 'unknown'];
        }
        foreach ($this->options['profiles'] as $profile) {
            if (! in_array($envelope['operation'], $profile['operations'] ?? [], true)) {
                continue;
            }
            $this->assertProfile($profile, $envelope);
            $scope = $this->scope($envelope, $this->account($profile, $configuration));
            try {
                $reference = $this->ownedReference($scope, $handle);

                return ['status' => 'accepted', 'external_reference_handle' => $reference['handle']];
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'sandbox_reference_not_owned') {
                    throw $exception;
                }
            }
        }

        return ['status' => 'unknown'];
    }

    public function request(array $envelope, array $arguments, array $configuration, array $credentials, string $packageRoot): array
    {
        Invocation::fromArray($envelope);
        $this->evidence = ['external_references' => [], 'network_access' => false];
        $this->secretValues = [];
        if ($envelope['package_id'] !== $this->options['package_id'] || $envelope['environment'] !== 'sandbox' || ($envelope['deployment_environment'] ?? null) !== 'local') {
            throw new RuntimeException('sandbox_package_or_environment_denied');
        }
        $this->assertHostOwned($packageRoot);
        if (array_diff(array_keys($arguments), ['profile', 'operation_key', 'parameters', 'idempotency_key', 'body']) !== [] || ($arguments['operation_key'] ?? null) !== $envelope['operation'] || ($arguments['idempotency_key'] ?? null) !== $envelope['idempotency_key'] || ! is_array($arguments['parameters'] ?? null) || array_diff(array_keys($arguments['parameters']), ['external_reference_handle']) !== []) {
            throw new RuntimeException('sandbox_invocation_binding_denied');
        }
        $name = $arguments['profile'] ?? '';
        $profile = $this->options['profiles'][$name] ?? throw new RuntimeException('sandbox_profile_not_registered');
        $this->assertProfile($profile, $envelope);
        if (strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > PayloadLimits::serviceRequestForPackage($envelope['package_id'])) {
            throw new InvalidArgumentException('sandbox_request_limit');
        }
        $body = $arguments['body'] ?? [];
        if (! is_array($body) || ! JsonValue::object($body)) {
            throw new InvalidArgumentException('sandbox_body_object_required');
        }
        if (isset($profile['body_schema'])) {
            ResourceSchema::validateWireValues(self::schemaObject($profile['body_schema']), $body);
        } elseif ($body !== [] || ! in_array($profile['method'], ['GET', 'DELETE'], true)) {
            throw new InvalidArgumentException('sandbox_body_schema_required');
        }
        if (! empty($profile['body_rules'])) {
            throw new InvalidArgumentException('sandbox_use_portable_body_schema');
        }
        $account = $this->account($profile, $configuration);
        foreach ($profile['body_account_bindings'] ?? [] as $field => $configField) {
            $actual = self::value($body, $field);
            $expected = self::value($configuration, $configField);
            if ((! is_string($actual) && ! is_int($actual)) || (! is_string($expected) && ! is_int($expected)) || (string) $actual !== (string) $expected) {
                throw new RuntimeException('sandbox_body_account_mismatch');
            }
        }
        self::assertInvocationBody($profile, $envelope, $arguments);
        $scope = $this->scope($envelope, $account);
        $path = $profile['path'];
        $known = null;
        if (str_contains($path, '{external_reference}')) {
            $known = $this->ownedReference($scope, $arguments['parameters']['external_reference_handle'] ?? '');
            $path = str_replace('{external_reference}', rawurlencode($known['reference']), $path);
        } elseif ($arguments['parameters'] !== []) {
            throw new RuntimeException('sandbox_path_parameters_denied');
        }
        $url = rtrim($profile['base_url'], '/').$path;
        $endpoint = $this->endpoint($url);
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'UniScript-POS-Developer-Kit-Sandbox/1', 'X-Request-ID' => $envelope['invocation_id'], 'X-Correlation-ID' => $envelope['correlation_id'], 'Idempotency-Key' => hash('sha256', $scope.':'.$envelope['idempotency_key'])];
        foreach ($profile['config_headers'] as $header => $field) {
            $headers[$header] = $this->scalar(self::value($configuration, $field), 'sandbox_configuration_header_invalid', 240);
        }
        if (($profile['timezone_headers'] ?? false) === true && ($profile['success_policy'] ?? null) !== 'read_only_json') {
            $zone = new \DateTimeZone((string) ($configuration['timezone_name'] ?? ''));
            $offset = $configuration['timezone_offset_minutes'] ?? (int) floor((new \DateTimeImmutable('now', $zone))->getOffset() / 60);
            if ((! is_int($offset) && ! (is_string($offset) && preg_match('/\A-?[0-9]{1,4}\z/D', $offset))) || abs((int) $offset) > 840) {
                throw new RuntimeException('sandbox_timezone_configuration_invalid');
            }
            $headers['x-timezone-name'] = $zone->getName();
            $headers['x-timezone-offset'] = (string) (int) $offset;
        }
        $mutating = ($profile['success_policy'] ?? null) !== 'read_only_json';
        $bytes = $profile['method'] === 'GET' ? null : json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($bytes !== null && strlen($bytes) > PayloadLimits::serviceRequestForPackage($envelope['package_id'])) {
            throw new InvalidArgumentException('sandbox_request_limit');
        }
        $fingerprint = hash('sha256', self::canonical([$body, $url, $profile, $account, $envelope['credential_revision']]));
        $identity = [$scope, $name, $envelope['operation'], hash('sha256', $envelope['idempotency_key'])];
        if ($mutating) {
            $old = $this->claim($identity, $fingerprint);
            if ($old !== null) {
                return $this->previous($old);
            }
        }
        try {
            $headers += $this->authentication($profile, $credentials, $envelope['deadline']);
            if ($bytes !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            $response = $this->send($endpoint, $profile['method'], $headers, $bytes, $envelope['deadline'], 262144);
            $result = $this->interpret($response, $profile, $known, $credentials);
            $rawReference = $result['_reference'] ?? null;
            unset($result['_reference']);
            if ($result['status'] === 'accepted' && $rawReference !== null) {
                $accountScope = hash('sha256', self::canonical([$envelope['package_id'], $envelope['environment'], $account]));

                return $this->acceptReference($identity, $scope, $accountScope, $rawReference);
            }
            if ($mutating) {
                $this->finish($identity, $result['status'], $result, null, null);
            }

            return $result;
        } catch (Throwable) {
            if ($mutating) {
                $result = ['status' => 'unknown', 'reason_code' => 'managed_transport_outcome_unknown'];
                $this->finish($identity, $result['status'], $result, null, null);

                return $result;
            }
            throw new RuntimeException('sandbox_read_or_authentication_failed');
        }
    }

    private function assertProfile(array $profile, array $envelope): void
    {
        $allowed = ['key', 'profile_version', 'enabled', 'package_ids', 'operations', 'method', 'base_url', 'path', 'account_key', 'account_fields', 'numeric_account_fields', 'config_headers', 'authentication', 'allow_local_synthetic', 'body_schema', 'body_rules', 'body_account_bindings', 'body_invocation_projection', 'success_path', 'success_value', 'reference_path', 'success_policy', 'timezone_headers'];
        if (array_diff(array_keys($profile), $allowed) !== []) {
            throw new InvalidArgumentException('sandbox_profile_fields_unsupported');
        }
        if (($profile['profile_version'] ?? null) !== 1 || ($profile['enabled'] ?? false) !== true
            || ! is_array($profile['package_ids'] ?? null) || ! in_array($envelope['package_id'], $profile['package_ids'], true)
            || ! is_array($profile['operations'] ?? null) || ! in_array($envelope['operation'], $profile['operations'], true)
            || ! in_array($profile['method'] ?? null, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! is_array($profile['account_fields'] ?? null) || count($profile['account_fields']) > 8
            || ! is_array($profile['config_headers'] ?? null) || count($profile['config_headers']) > 12
            || ! is_string($profile['account_key'] ?? null) || ! preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $profile['account_key'])
            || ! is_array($profile['authentication'] ?? null)) {
            throw new InvalidArgumentException('sandbox_trusted_profile_invalid');
        }
        $path = $profile['path'] ?? null;
        if (! is_string($path) || ! str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f?#]/', $path)
            || str_contains($path, '..') || str_contains($path, '://') || strpbrk(str_replace('{external_reference}', '', $path), '{}') !== false
            || ! is_string($profile['base_url'] ?? null)) {
            throw new InvalidArgumentException('sandbox_fixed_endpoint_required');
        }
        $this->endpoint(rtrim($profile['base_url'], '/').str_replace('{external_reference}', 'reference', $path), false);
        foreach ($profile['account_fields'] as $name => $field) {
            if (! self::field($name) || ! self::field($field) || preg_match('/(?:password|secret|token|credential|api_?key|authorization)/i', $field)) {
                throw new InvalidArgumentException('sandbox_account_binding_invalid');
            }
        }
        $headerNames = array_map(strtolower(...), array_keys($profile['config_headers']));
        if (count(array_unique($headerNames)) !== count($headerNames)) {
            throw new InvalidArgumentException('sandbox_configuration_header_collision');
        }
        $hostOwnedHeaders = ['x-uniscript-challenge', 'x-timezone-name', 'x-timezone-offset'];
        foreach ($profile['config_headers'] as $header => $field) {
            if (! self::header($header) || in_array(strtolower($header), $hostOwnedHeaders, true) || ! self::field($field)
                || preg_match('/(?:password|secret|token|credential|api_?key|authorization)/i', $field)) {
                throw new InvalidArgumentException('sandbox_configuration_header_binding');
            }
        }
        if (isset($profile['body_schema'], $profile['body_rules'])) {
            throw new InvalidArgumentException('sandbox_body_validator_ambiguous');
        }
        if (isset($profile['body_schema'])) {
            if (! is_array($profile['body_schema']) || $profile['method'] === 'GET') {
                throw new InvalidArgumentException('sandbox_body_schema_unsupported');
            }
            try {
                ResourceSchema::assertWireDefinition(self::schemaObject($profile['body_schema']));
            } catch (Throwable) {
                throw new InvalidArgumentException('sandbox_body_schema_unsupported');
            }
        }
        if (isset($profile['numeric_account_fields']) && (! is_array($profile['numeric_account_fields'])
            || ! array_is_list($profile['numeric_account_fields']) || array_diff($profile['numeric_account_fields'], array_keys($profile['account_fields'])) !== [])) {
            throw new InvalidArgumentException('sandbox_numeric_account_binding_invalid');
        }
        if (isset($profile['body_account_bindings'])) {
            if (! is_array($profile['body_account_bindings']) || count($profile['body_account_bindings']) > 8) {
                throw new InvalidArgumentException('sandbox_body_account_binding_invalid');
            }
            foreach ($profile['body_account_bindings'] as $source => $target) {
                if (! self::field($source) || ! self::field($target)) {
                    throw new InvalidArgumentException('sandbox_body_account_binding_invalid');
                }
            }
        }
        if (isset($profile['body_invocation_projection'])) {
            $projection = $profile['body_invocation_projection'];
            if (! is_array($projection) || $projection === [] || count($projection) > 16) {
                throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
            }
            foreach ($projection as $target => $rule) {
                if (! self::field($target) || ! is_array($rule) || array_diff(array_keys($rule), ['source', 'field_map']) !== [] || ! self::field($rule['source'] ?? null)) {
                    throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
                }
                if (isset($rule['field_map'])) {
                    if (! is_array($rule['field_map']) || $rule['field_map'] === [] || count($rule['field_map']) > 32) {
                        throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
                    }
                    foreach ($rule['field_map'] as $source => $destination) {
                        if (! self::field($source) || str_contains($source, '.') || ! self::field($destination)) {
                            throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
                        }
                    }
                }
            }
        }
        $auth = $profile['authentication'];
        $fields = match ($auth['scheme'] ?? null) {
            'basic' => ['username_field', 'password_field'], 'bearer' => ['token_field'], 'header_secret' => ['secret_field'],
            'oauth2_client_credentials' => ['client_id_field', 'client_secret_field'],
            default => throw new InvalidArgumentException('sandbox_authentication_unsupported'),
        };
        $authFields = ['scheme', ...$fields, ...($auth['scheme'] === 'header_secret' ? ['header'] : []),
            ...($auth['scheme'] === 'oauth2_client_credentials' ? ['token_url', 'client_authentication', 'scopes'] : [])];
        if (array_diff(array_keys($auth), $authFields) !== []) {
            throw new InvalidArgumentException('sandbox_authentication_fields_unsupported');
        }
        foreach ($fields as $field) {
            if (! self::field($auth[$field] ?? null)) {
                throw new InvalidArgumentException('sandbox_credential_binding_required');
            }
        }
        if ($auth['scheme'] === 'header_secret' && (! self::header($auth['header'] ?? null)
            || in_array(strtolower($auth['header']), [...$headerNames, ...$hostOwnedHeaders], true))) {
            throw new InvalidArgumentException('sandbox_secret_header_invalid');
        }
        if ($auth['scheme'] === 'oauth2_client_credentials') {
            if (! in_array($auth['client_authentication'] ?? null, ['basic', 'body'], true) || ! is_array($auth['scopes'] ?? null)
                || ! array_is_list($auth['scopes']) || count($auth['scopes']) > 20) {
                throw new InvalidArgumentException('sandbox_oauth_profile_invalid');
            }
            foreach ($auth['scopes'] as $scope) {
                if (! is_string($scope) || ! preg_match('/\A[A-Za-z0-9:._-]{1,100}\z/D', $scope)) {
                    throw new InvalidArgumentException('sandbox_oauth_scope_invalid');
                }
            }
            $this->endpoint($auth['token_url'] ?? '', false);
        }
        if (($profile['success_policy'] ?? null) === 'read_only_json' && ($profile['method'] !== 'GET' || str_starts_with($envelope['operation'], 'orders.'))) {
            throw new InvalidArgumentException('sandbox_read_profile_cannot_accept_orders');
        }
        foreach (['reference_path', 'success_path'] as $field) {
            if (isset($profile[$field]) && ! self::field($profile[$field])) {
                throw new InvalidArgumentException('sandbox_response_binding_invalid');
            }
        }
    }

    private function account(array $profile, array $configuration): array
    {
        $url = parse_url($profile['base_url']);
        $account = ['account_key' => $profile['account_key'], 'endpoint' => strtolower($url['scheme'].'://'.$url['host']).':'.($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80)).rtrim($url['path'] ?? '', '/')];
        foreach ($profile['account_fields'] as $name => $field) {
            if (! self::field($name) || ! self::field($field) || preg_match('/(?:password|secret|token|credential|api_?key|authorization)/i', $field)) {
                throw new InvalidArgumentException('sandbox_account_binding_invalid');
            }
            $value = $this->scalar(self::value($configuration, $field), 'sandbox_account_configuration_required', 240);
            if (in_array($name, $profile['numeric_account_fields'] ?? [], true)) {
                if (! ctype_digit($value)) {
                    throw new InvalidArgumentException('sandbox_numeric_account_required');
                }
                $value = ltrim($value, '0') ?: '0';
            }
            $account[$name] = $value;
        }

        return $account;
    }

    private function authentication(array $profile, array $credentials, string $deadline): array
    {
        if (isset($profile['body_invocation_projection'])) {
            $projection = $profile['body_invocation_projection'];
            if (! is_array($projection) || $projection === [] || count($projection) > 16) {
                throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
            }
            foreach ($projection as $target => $rule) {
                if (! self::field($target) || ! is_array($rule) || array_diff(array_keys($rule), ['source', 'field_map']) !== [] || ! self::field($rule['source'] ?? null)) {
                    throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
                }
                if (isset($rule['field_map'])) {
                    if (! is_array($rule['field_map']) || $rule['field_map'] === [] || count($rule['field_map']) > 32) {
                        throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
                    }
                    foreach ($rule['field_map'] as $source => $destination) {
                        if (! self::field($source) || str_contains($source, '.') || ! self::field($destination)) {
                            throw new InvalidArgumentException('sandbox_invocation_projection_invalid');
                        }
                    }
                }
            }
        }
        $auth = $profile['authentication'];
        $secret = fn (string $field): string => $this->scalar(self::value($credentials, $field), 'sandbox_credentials_required', 8192);
        if ($auth['scheme'] === 'basic') {
            $value = 'Basic '.base64_encode($secret($auth['username_field']).':'.$secret($auth['password_field']));
            $this->secretValues[] = $value;

            return ['Authorization' => $value];
        }
        if ($auth['scheme'] === 'bearer') {
            $value = 'Bearer '.$secret($auth['token_field']);
            $this->secretValues[] = $value;

            return ['Authorization' => $value];
        }
        if ($auth['scheme'] === 'header_secret') {
            return [$auth['header'] => $secret($auth['secret_field'])];
        }
        $body = ['grant_type' => 'client_credentials'];
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded', 'User-Agent' => 'UniScript-POS-Developer-Kit-Sandbox/1'];
        if ($auth['client_authentication'] === 'basic') {
            $headers['Authorization'] = 'Basic '.base64_encode($secret($auth['client_id_field']).':'.$secret($auth['client_secret_field']));
        } else {
            $body += ['client_id' => $secret($auth['client_id_field']), 'client_secret' => $secret($auth['client_secret_field'])];
        }
        if ($auth['scopes'] !== []) {
            $body['scope'] = implode(' ', $auth['scopes']);
        }
        $response = $this->send($this->endpoint($auth['token_url']), 'POST', $headers, http_build_query($body, '', '&', PHP_QUERY_RFC3986), $deadline, 16384, 5);
        $token = json_decode($response['body'], true, 10, JSON_THROW_ON_ERROR);
        if ($response['status'] < 200 || $response['status'] >= 300 || ! is_array($token) || strtolower((string) ($token['token_type'] ?? '')) !== 'bearer') {
            throw new RuntimeException('sandbox_oauth_rejected');
        }
        $accessToken = $this->scalar($token['access_token'] ?? null, 'sandbox_oauth_token_invalid', 8192);
        if (preg_match('/[\x00-\x20\x7f]/', $accessToken)) {
            throw new RuntimeException('sandbox_oauth_token_invalid');
        }
        $this->secretValues[] = $accessToken;
        $this->secretValues[] = 'Bearer '.$accessToken;

        return ['Authorization' => 'Bearer '.$accessToken];
    }

    private function interpret(array $response, array $profile, ?array $known, array $credentials): array
    {
        $readOnly = ($profile['success_policy'] ?? null) === 'read_only_json';
        $successful = $response['status'] >= 200 && $response['status'] < 300;
        $decoded = trim($response['body']) === '' ? null : JsonDocument::decode($response['body'], true);
        if ($readOnly) {
            if (! $successful || ! is_array($decoded) || (isset($profile['success_path']) && self::value($decoded, $profile['success_path']) !== ($profile['success_value'] ?? null))) {
                return ['status' => 'rejected', 'reason_code' => 'managed_read_rejected'];
            }

            return ['status' => 'accepted', 'data' => $this->redact($decoded, $credentials)];
        }
        if (! $successful) {
            return ['status' => 'unknown', 'reason_code' => 'managed_acceptance_unknown'];
        }
        $successValue = is_array($decoded) && isset($profile['success_path']) ? self::value($decoded, $profile['success_path']) : null;
        $reference = null;
        if ($known !== null && ($profile['success_policy'] ?? null) === 'existing_reference_on_2xx') {
            if ($successValue !== null && $successValue !== ($profile['success_value'] ?? null)) {
                return ['status' => 'unknown', 'reason_code' => 'managed_acceptance_unknown'];
            }
            $reference = is_array($decoded) && isset($profile['reference_path']) ? self::value($decoded, $profile['reference_path']) : null;
            $reference ??= $known['reference'];
        } elseif (is_array($decoded) && $successValue === ($profile['success_value'] ?? null) && isset($profile['reference_path'])) {
            $reference = self::value($decoded, $profile['reference_path']);
        }
        if ((! is_string($reference) && ! is_int($reference)) || ! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', (string) $reference)) {
            return ['status' => 'unknown', 'reason_code' => 'managed_acceptance_unknown'];
        }
        if ($known !== null && ! hash_equals($known['reference'], (string) $reference)) {
            return ['status' => 'unknown', 'reason_code' => 'managed_reference_mismatch'];
        }

        return ['status' => 'accepted', '_reference' => (string) $reference];
    }

    private function endpoint(string $url, bool $resolve = true): array
    {
        $parts = parse_url($url);
        $loopback = ($this->options['test_loopback'] ?? false) === true;
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! in_array($parts['scheme'] ?? null, $loopback ? ['https', 'http'] : ['https'], true) || ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/iD', $parts['host']) || str_contains($parts['host'], '..') || str_contains($parts['path'] ?? '', '..')) {
            throw new InvalidArgumentException('sandbox_https_fixed_url_required');
        }
        if (! $resolve) {
            return $parts;
        }
        $host = strtolower($parts['host']);
        if (preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/iD', $host) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException('sandbox_ambiguous_numeric_host_denied');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } elseif ($this->resolver !== null) {
            $addresses = ($this->resolver)($host);
        } else {
            $addresses = [];
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        if (! is_array($addresses) || $addresses === [] || count($addresses) > 32) {
            throw new RuntimeException('sandbox_dns_unavailable');
        }
        foreach ($addresses as $address) {
            if (! is_string($address) || ! self::publicAddress($address)) {
                if (! $loopback || $address !== '127.0.0.1' || ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
                    throw new RuntimeException('sandbox_private_destination_denied');
                }
            }
        }

        return [...$parts, 'url' => $url, 'host' => $host, 'port' => $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80), 'address' => $addresses[0]];
    }

    private function send(array $endpoint, string $method, array $headers, ?string $body, string $deadline, int $maximum, int $timeoutSeconds = 10): array
    {
        $remaining = strtotime($deadline) - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('sandbox_deadline_expired');
        }
        $this->evidence['network_access'] = true;
        if ($this->sender !== null) {
            $response = ($this->sender)(['endpoint' => $endpoint, 'method' => $method, 'headers' => $headers, 'body' => $body, 'maximum' => $maximum, 'deadline' => $deadline]);
            if (! is_array($response) || ! is_int($response['status'] ?? null) || ! is_string($response['body'] ?? null) || strlen($response['body']) > $maximum) {
                throw new RuntimeException('sandbox_response_limit_or_shape');
            }

            return $response;
        }
        $curl = curl_init($endpoint['url']);
        $responseBody = '';
        $headerBytes = 0;
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name.': '.$value, array_keys($headers), array_values($headers)), CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT_MS => min(3000, max(1, (int) ($remaining * 1000))), CURLOPT_TIMEOUT_MS => min($timeoutSeconds * 1000, max(1, (int) ($remaining * 1000))), CURLOPT_RESOLVE => [$endpoint['host'].':'.$endpoint['port'].':'.(str_contains($endpoint['address'], ':') ? '['.$endpoint['address'].']' : $endpoint['address'])], CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $bytes) use (&$responseBody, $maximum): int {
                if (strlen($responseBody) + strlen($bytes) > $maximum) {
                    return 0;
                } $responseBody .= $bytes;

                return strlen($bytes);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $bytes) use (&$headerBytes): int {
                $headerBytes += strlen($bytes);

                return $headerBytes <= 16384 ? strlen($bytes) : 0;
            }];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = $endpoint['scheme'] === 'http' ? 'HTTP' : 'HTTPS';
        } else {
            $options[CURLOPT_PROTOCOLS] = $endpoint['scheme'] === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $ip = curl_getinfo($curl, CURLINFO_PRIMARY_IP);
        unset($curl);
        if ($ok === false || inet_pton($ip) !== inet_pton($endpoint['address'])) {
            throw new RuntimeException('sandbox_transport_failed');
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    private function claim(array $identity, string $fingerprint): ?array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $query = $this->db->prepare('SELECT * FROM sandbox_attempts WHERE scope=? AND profile=? AND operation=? AND logical_key=?');
            $query->execute($identity);
            $old = $query->fetch(PDO::FETCH_ASSOC);
            if ($old !== false && ! hash_equals($old['fingerprint'], $fingerprint)) {
                throw new RuntimeException('sandbox_idempotency_conflict');
            }
            if ($old === false) {
                $this->db->prepare('INSERT INTO sandbox_attempts(scope,profile,operation,logical_key,fingerprint,status) VALUES(?,?,?,?,?,?)')->execute([...$identity, $fingerprint, 'claimed']);
            }
            $this->db->exec('COMMIT');

            return $old === false ? null : $old;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    private function previous(array $old): array
    {
        if ($old['status'] === 'accepted' && $old['reference'] !== null && $old['handle'] !== null) {
            $owner = $this->db->prepare('SELECT status FROM sandbox_reference_owners WHERE handle=?');
            $owner->execute([$old['handle']]);
            if ($owner->fetchColumn() !== 'accepted') {
                return ['status' => 'unknown', 'reason_code' => 'managed_reference_ownership_conflict'];
            }
            $this->evidence['external_references'][] = ['handle' => $old['handle'], 'reference' => $this->vault->open($old['reference'])['value']];
        }

        return $old['response'] !== null ? $this->vault->open($old['response']) : ['status' => 'unknown', 'reason_code' => 'sandbox_remote_outcome_unknown'];
    }

    private function finish(array $identity, string $status, array $response, ?string $handle, ?string $reference): void
    {
        $this->db->prepare('UPDATE sandbox_attempts SET status=?,response=?,handle=?,reference=? WHERE scope=? AND profile=? AND operation=? AND logical_key=?')->execute([$status, $this->vault->seal($response), $handle, $reference === null ? null : $this->vault->seal(['value' => $reference]), ...$identity]);
    }

    private function acceptReference(array $identity, string $scope, string $accountScope, string $reference): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $query = $this->db->prepare('SELECT * FROM sandbox_reference_owners WHERE account_scope=? AND reference_sha256=?');
            $query->execute([$accountScope, hash('sha256', $reference)]);
            $owner = $query->fetch(PDO::FETCH_ASSOC);
            $conflict = $owner !== false && ($owner['status'] !== 'accepted' || $owner['connection_scope'] !== $scope || ($identity[2] === 'orders.submit' && [$owner['connection_scope'], $owner['owner_profile'], $owner['owner_operation'], $owner['owner_logical_key']] !== $identity));
            if ($conflict) {
                $result = ['status' => 'unknown', 'reason_code' => 'managed_reference_ownership_conflict'];
                $this->db->prepare('UPDATE sandbox_reference_owners SET status=? WHERE handle=?')->execute(['ambiguous', $owner['handle']]);
                $this->db->prepare('UPDATE sandbox_attempts SET status=?,response=? WHERE handle=?')->execute(['unknown', $this->vault->seal($result), $owner['handle']]);
                $this->finish($identity, 'unknown', $result, null, null);
                $this->db->exec('COMMIT');

                return $result;
            }
            $handle = $owner === false ? WorkbenchState::uuid() : $owner['handle'];
            if ($owner === false) {
                $this->db->prepare('INSERT INTO sandbox_reference_owners VALUES(?,?,?,?,?,?,?,?,?)')->execute([$accountScope, hash('sha256', $reference), $scope, $identity[1], $identity[2], $identity[3], $handle, $this->vault->seal(['value' => $reference]), 'accepted']);
            }
            $result = ['status' => 'accepted', 'external_reference_handle' => $handle];
            $this->finish($identity, 'accepted', $result, $handle, $reference);
            $this->db->exec('COMMIT');
            $this->evidence['external_references'][] = ['handle' => $handle, 'reference' => $reference];

            return $result;
        } catch (Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
    }

    private function ownedReference(string $scope, mixed $handle): array
    {
        if (! is_string($handle)) {
            throw new RuntimeException('sandbox_owned_reference_required');
        }
        $query = $this->db->prepare('SELECT a.handle,a.reference FROM sandbox_attempts a JOIN sandbox_reference_owners o ON o.handle=a.handle WHERE a.scope=? AND a.handle=? AND a.status=? AND o.status=\'accepted\' AND o.connection_scope=a.scope LIMIT 1');
        $query->execute([$scope, $handle, 'accepted']);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['reference'] === null) {
            throw new RuntimeException('sandbox_reference_not_owned');
        }

        return ['handle' => $row['handle'], 'reference' => $this->vault->open($row['reference'])['value']];
    }

    private function scope(array $envelope, array $account): string
    {
        return hash('sha256', self::canonical([$envelope['package_id'], $envelope['organization_id'], $envelope['location_id'], $envelope['connection_id'], $envelope['environment'], $account]));
    }

    public static function assertInvocationBody(array $profile, array $envelope, array $arguments): void
    {
        if (! in_array($envelope['operation'], ['orders.reconcile', 'orders.status.update', 'orders.shipping.update'], true)) {
            return;
        }
        $expectedHandle = $envelope['input']['external_reference_handle'] ?? null;
        $actualHandle = $arguments['parameters']['external_reference_handle'] ?? null;
        if (($actualHandle !== null && $actualHandle !== $expectedHandle) || ($envelope['operation'] !== 'orders.reconcile' && (! is_string($expectedHandle) || $actualHandle !== $expectedHandle))) {
            throw new RuntimeException('sandbox_order_action_reference_changed');
        }
        if ($envelope['operation'] === 'orders.reconcile') {
            if (($profile['method'] ?? 'GET') !== 'GET' || ! empty($arguments['body'])) {
                throw new RuntimeException('sandbox_reconciliation_must_be_read_only');
            }

            return;
        }
        $projection = $profile['body_invocation_projection'] ?? null;
        if (! is_array($projection) || $projection === []) {
            throw new RuntimeException('sandbox_order_action_projection_required');
        }
        $expected = [];
        foreach ($projection as $target => $rule) {
            $value = self::value($envelope['input'], $rule['source']);
            if (isset($rule['field_map'])) {
                if (! is_array($value) || array_diff(array_keys($value), array_keys($rule['field_map'])) !== []) {
                    throw new RuntimeException('sandbox_order_action_projection_invalid');
                }
                $mapped = [];
                foreach ($value as $field => $child) {
                    self::set($mapped, $rule['field_map'][$field], $child);
                }
                $value = $mapped;
            }
            self::set($expected, $target, $value);
        }
        if (! is_array($arguments['body'] ?? null) || ! hash_equals(hash('sha256', self::canonical($expected)), hash('sha256', self::canonical($arguments['body'])))) {
            throw new RuntimeException('sandbox_order_action_payload_changed');
        }
    }

    private function redact(mixed $value, array $credentials): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/(?:^|_)(?:password|secret|access_token|refresh_token|authorization|credentials?|api_?key)(?:_|$)/i', $key)) {
                    unset($value[$key]);
                } else {
                    $value[$key] = $this->redact($child, $credentials);
                }
            }

            return $value;
        }

        if (is_string($value)) {
            foreach ([...self::credentialStrings($credentials), ...$this->secretValues] as $secret) {
                if (is_string($secret) && ($value === $secret || (strlen($secret) >= 4 && str_contains($value, $secret)))) {
                    return '[redacted]';
                }
            }
        }

        return $value;
    }

    private function scalar(mixed $value, string $reason, int $maximum): string
    {
        if ((! is_string($value) && ! is_int($value)) || ($value = (string) $value) === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException($reason);
        }

        return $value;
    }

    private static function publicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($address, ':')) {
            $bytes = inet_pton($address);

            return $bytes !== false && (ord($bytes[0]) & 0xE0) === 0x20 && ! self::cidr($address, '2001::/23') && ! self::cidr($address, '2001:db8::/32') && ! self::cidr($address, '2002::/16') && ! self::cidr($address, '3fff::/20');
        }
        foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $range) {
            if (self::cidr($address, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function cidr(string $address, string $range): bool
    {
        [$base, $bits] = explode('/', $range);
        $ip = inet_pton($address);
        $network = inet_pton($base);
        if ($ip === false || $network === false || strlen($ip) !== strlen($network)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $remaining = (int) $bits % 8;

        return substr($ip, 0, $bytes) === substr($network, 0, $bytes) && ($remaining === 0 || (ord($ip[$bytes]) & (0xFF << (8 - $remaining))) === (ord($network[$bytes]) & (0xFF << (8 - $remaining))));
    }

    private static function field(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 100 && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,5}\z/D', $value) === 1;
    }

    private static function credentialStrings(array $credentials): array
    {
        $values = [];
        array_walk_recursive($credentials, static function (mixed $value) use (&$values): void {
            if (is_string($value)) {
                $values[] = $value;
            }
        });

        return $values;
    }

    private static function header(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z][A-Za-z0-9-]{0,79}\z/D', $value) === 1 && ! in_array(strtolower($value), ['authorization', 'proxy-authorization', 'host', 'cookie', 'set-cookie', 'content-length', 'content-type', 'connection', 'transfer-encoding', 'idempotency-key', 'x-request-id', 'x-correlation-id'], true);
    }

    private static function value(array $value, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    private static function set(array &$array, string $path, mixed $value): void
    {
        $cursor = &$array;
        foreach (explode('.', $path) as $key) {
            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $cursor[$key] = [];
            } $cursor = &$cursor[$key];
        } $cursor = $value;
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return json_encode(array_map(self::canonical(...), $value), JSON_THROW_ON_ERROR);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function schemaObject(array $schema): \stdClass
    {
        if (($schema['type'] ?? null) === 'object') {
            $schema['properties'] = (object) array_map(static fn (array $child): \stdClass => self::schemaObject($child), $schema['properties'] ?? []);
        } elseif (($schema['type'] ?? null) === 'array') {
            $schema['items'] = self::schemaObject($schema['items']);
        }

        return (object) $schema;
    }
}
