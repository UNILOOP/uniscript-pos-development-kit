<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Trusted identity proof, independent from uploaded package success messages. */
final class DevelopmentConnectionEvidence
{
    public function __construct(private readonly DevelopmentSandboxTransport $transport) {}

    public function verify(array $deployment, array $configuration, array $credentials, array $scope = []): array
    {
        $packageId = $deployment['manifest']['package_id'] ?? null;
        if ($packageId !== $this->transport->packageId() || ($scope['environment'] ?? 'sandbox') !== 'sandbox' || ! is_string($deployment['package_root'] ?? null)) {
            throw new InvalidArgumentException('sandbox_verification_environment_denied');
        }
        $descriptor = $this->descriptor($packageId);
        $profile = $this->transport->profile($descriptor['transport_profile'], 'connection.test');
        if ($profile['method'] !== 'GET' || ($profile['success_policy'] ?? null) !== 'read_only_json' || str_contains($profile['path'], '{')) {
            throw new InvalidArgumentException('sandbox_verification_read_profile_required');
        }
        if ($descriptor['synthetic_only'] && ($profile['allow_local_synthetic'] ?? false) !== true) {
            throw new InvalidArgumentException('sandbox_verification_synthetic_profile_required');
        }
        if (! $descriptor['synthetic_only'] && ($profile['allow_local_synthetic'] ?? false) === true) {
            throw new InvalidArgumentException('sandbox_verification_real_profile_cannot_use_synthetic_transport');
        }
        $account = $this->account($profile, $configuration);
        foreach ($descriptor['account_response_bindings'] as $path => $name) {
            if (! self::field($path) || ! is_string($name) || ! array_key_exists($name, $account)) {
                throw new InvalidArgumentException('sandbox_verification_account_binding_invalid');
            }
        }
        if (array_diff(array_keys($account), array_values($descriptor['account_response_bindings'])) !== []) {
            throw new InvalidArgumentException('sandbox_verification_account_proof_incomplete');
        }
        $envelope = ['protocol_version' => '1', 'invocation_id' => 'verify-'.bin2hex(random_bytes(16)),
            'command_id' => 'verify-command-'.bin2hex(random_bytes(8)), 'attempt_id' => 'verify-attempt-'.bin2hex(random_bytes(8)),
            'correlation_id' => 'verify-correlation-'.bin2hex(random_bytes(8)), 'package_id' => $packageId,
            'execution_revision' => $deployment['execution_revision'] ?? '', 'connection_id' => $scope['connection_id'] ?? 'development-connection',
            'organization_id' => $scope['organization_id'] ?? 'development-organization', 'location_id' => $scope['location_id'] ?? 'development-location',
            'environment' => 'sandbox', 'deployment_environment' => 'local', 'actor_id' => $scope['actor_id'] ?? 'development-pharmacy',
            'operation' => 'connection.test', 'schema_version' => '1', 'routing_revision' => (string) ($scope['routing_revision'] ?? 1),
            'configuration_revision' => (string) ($scope['configuration_revision'] ?? 1), 'mapping_revision' => 'verification',
            'credential_revision' => (string) ($scope['credential_revision'] ?? 1), 'idempotency_key' => 'verify-'.bin2hex(random_bytes(16)),
            'deadline' => $scope['deadline'] ?? gmdate('Y-m-d\TH:i:s\Z', time() + 10), 'request_budget' => 1, 'input' => [], 'service_grant' => bin2hex(random_bytes(32))];
        Invocation::fromArray($envelope);
        $binding = $this->transport->configurationHash();
        $challenge = isset($descriptor['challenge_path']) ? bin2hex(random_bytes(32)) : null;
        try {
            $response = $this->transport->verificationRequest($envelope, $descriptor, $configuration, $credentials, $deployment['package_root'], $challenge === null ? [] : ['X-UniScript-Challenge' => $challenge]);
            if (! is_int($response['status'] ?? null) || ! is_string($response['body'] ?? null) || strlen($response['body']) > 16384) {
                throw new RuntimeException('sandbox_verification_response_invalid');
            }
            $decoded = JsonDocument::decode($response['body'], true);
        } catch (Throwable) {
            throw new RuntimeException('sandbox_verification_http_failed');
        }
        if ($response['status'] < 200 || $response['status'] >= 300 || ! is_array($decoded)
            || self::value($decoded, $descriptor['success_path']) !== $descriptor['success_value']) {
            throw new RuntimeException('sandbox_verification_authentication_unproven');
        }
        $principal = self::value($decoded, $descriptor['principal_path']);
        if ((! is_string($principal) && ! is_int($principal)) || (string) $principal === '' || strlen((string) $principal) > 240 || preg_match('/[\x00-\x20\x7f]/', (string) $principal)) {
            throw new RuntimeException('sandbox_verification_principal_invalid');
        }
        if ($challenge !== null) {
            $actual = self::value($decoded, $descriptor['challenge_path']);
            if (! is_string($actual) || ! hash_equals($challenge, $actual)) {
                throw new RuntimeException('sandbox_verification_challenge_mismatch');
            }
        }
        if ($descriptor['synthetic_only'] && ($decoded['synthetic'] ?? false) !== true) {
            throw new RuntimeException('sandbox_verification_synthetic_identity_required');
        }
        foreach ($descriptor['account_response_bindings'] as $path => $name) {
            $actual = self::value($decoded, $path);
            if ((is_string($actual) || is_int($actual)) && in_array($name, $profile['numeric_account_fields'] ?? [], true) && ctype_digit((string) $actual)) {
                $actual = ltrim((string) $actual, '0') ?: '0';
            }
            if ((! is_string($actual) && ! is_int($actual)) || ! hash_equals($account[$name], (string) $actual)) {
                throw new RuntimeException('sandbox_verification_account_mismatch');
            }
        }
        if ($binding !== $this->transport->configurationHash() || $descriptor !== $this->transport->verificationDescriptor()) {
            throw new RuntimeException('sandbox_verification_policy_changed');
        }
        $parts = parse_url($profile['base_url']);
        $identity = ['realm' => 'managed_vendor', 'family' => $profile['account_key'],
            'endpoint' => strtolower($parts['scheme'].'://'.$parts['host']).':'.($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)).rtrim($parts['path'] ?? '', '/'),
            'environment' => 'sandbox', 'account' => $account, 'principal_id' => (string) $principal];
        $rawDescriptor = $descriptor;
        unset($rawDescriptor['key']);
        $policyHash = self::hash(['verification' => $rawDescriptor, 'transport' => [...$profile, 'key' => $descriptor['transport_profile']]]);

        return ['verified' => true, 'profile_sha256' => self::hash(['identity' => $identity, 'profile_sha256' => $policyHash]),
            'principal_fingerprint' => hash('sha256', (string) $principal), 'account_fingerprint' => self::hash($identity),
            'synthetic' => $descriptor['synthetic_only'], 'simulated' => $descriptor['synthetic_only'],
            'provenance' => $descriptor['synthetic_only'] ? 'managed_profile_mock' : 'managed_profile_http'];
    }

    private function descriptor(string $packageId): array
    {
        $descriptor = $this->transport->verificationDescriptor();
        $required = ['key', 'profile_version', 'enabled', 'package_ids', 'transport_profile', 'principal_path', 'success_path', 'success_value', 'account_response_bindings', 'synthetic_only'];
        if (! is_array($descriptor) || array_diff($required, array_keys($descriptor)) !== [] || array_diff(array_keys($descriptor), [...$required, 'challenge_path']) !== []
            || ! is_string($descriptor['key']) || ! preg_match('/\A[a-z][a-z0-9._-]{0,79}\z/D', $descriptor['key'])
            || $descriptor['profile_version'] !== 1 || $descriptor['enabled'] !== true || ! is_array($descriptor['package_ids']) || ! in_array($packageId, $descriptor['package_ids'], true)
            || ! is_string($descriptor['transport_profile']) || ! self::field($descriptor['principal_path']) || ! self::field($descriptor['success_path'])
            || ! is_scalar($descriptor['success_value']) || ! is_bool($descriptor['synthetic_only']) || ! is_array($descriptor['account_response_bindings'])
            || count($descriptor['account_response_bindings']) > 8 || (isset($descriptor['challenge_path']) && ! self::field($descriptor['challenge_path']))) {
            throw new InvalidArgumentException('sandbox_verification_descriptor_required');
        }

        return $descriptor;
    }

    private function account(array $profile, array $configuration): array
    {
        $values = [];
        foreach ($profile['account_fields'] as $name => $field) {
            $value = self::value($configuration, $field);
            if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '' || strlen((string) $value) > 240 || preg_match('/[\x00-\x1f\x7f]/', (string) $value)) {
                throw new InvalidArgumentException('sandbox_verification_account_configuration_required');
            }
            $value = trim((string) $value);
            if (in_array($name, $profile['numeric_account_fields'] ?? [], true)) {
                if (! ctype_digit($value)) {
                    throw new InvalidArgumentException('sandbox_verification_numeric_account_required');
                }
                $value = ltrim($value, '0') ?: '0';
            }
            $values[$name] = $value;
        }
        ksort($values);

        return $values;
    }

    private static function value(array $values, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            $values = is_array($values) ? ($values[$key] ?? null) : null;
        }

        return $values;
    }

    private static function field(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 100 && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,5}\z/D', $value) === 1;
    }

    private static function hash(array $value): string
    {
        $canonical = static function (array $value) use (&$canonical): array {
            ksort($value);
            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $value[$key] = $canonical($child);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }
}
