<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Certifier-channel input only. Never read this authority from extracted package files. */
final class ReviewedConformance
{
    public static function validate(array $profile, string $packageId, string $version, ?string $source = null, ?array $operations = null): array
    {
        $keys = ['version', 'package_id', 'package_version', 'source_sha256', 'readiness_operation', 'production_enabled', 'scenarios'];
        if (strlen(json_encode($profile, JSON_THROW_ON_ERROR)) > 262144 || array_diff(array_keys($profile), $keys) !== [] || array_diff($keys, array_keys($profile)) !== []
            || $profile['version'] !== 1 || $profile['package_id'] !== $packageId || $profile['package_version'] !== $version
            || ! is_string($profile['source_sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $profile['source_sha256']) || ($source !== null && $profile['source_sha256'] !== $source)
            || $profile['readiness_operation'] !== 'identity.describe' || ! is_bool($profile['production_enabled']) || ! is_array($profile['scenarios']) || count($profile['scenarios']) > 100 || ! isset($profile['scenarios']['identity.describe'])) {
            throw new RuntimeException('reviewed_conformance_scope_invalid');
        }
        if ($operations !== null && (array_diff($operations, array_keys($profile['scenarios'])) !== [] || array_diff(array_keys($profile['scenarios']), $operations) !== [])) {
            throw new RuntimeException('reviewed_conformance_coverage_invalid');
        }
        $allowed = ['input', 'kind', 'expected_calls', 'expected_event', 'expected_events', 'expected_http_body', 'expected_http_by_operation', 'expected_http_operations', 'expected_http_projection', 'expected_proposals', 'expected_result_projection', 'services', 'servicesByOperation'];
        foreach ($profile['scenarios'] as $operation => $scenarios) {
            if (! is_string($operation) || ! preg_match('/\A[a-z][a-z0-9_.-]{1,99}\z/D', $operation) || ! is_array($scenarios) || ! array_is_list($scenarios) || $scenarios === [] || count($scenarios) > 100) {
                throw new RuntimeException('reviewed_conformance_scenarios_invalid');
            }
            foreach ($scenarios as $scenario) {
                if (! is_array($scenario) || array_diff(array_keys($scenario), $allowed) !== [] || ! is_array($scenario['input'] ?? null)
                    || ! in_array($scenario['kind'] ?? null, Result::KINDS, true) || ! is_array($scenario['expected_calls'] ?? null) || ! array_is_list($scenario['expected_calls']) || count($scenario['expected_calls']) > 100
                ) {
                    throw new RuntimeException('reviewed_conformance_scenarios_invalid');
                }
                if (($scenario['kind'] === 'remote_acceptance' && in_array($operation, ['orders.submit', 'orders.status.update', 'orders.shipping.update'], true) && ! in_array('http.request', $scenario['expected_calls'], true))
                    || ($operation === 'inbound.decode' && $scenario['kind'] === 'success' && ! in_array('commands.propose', $scenario['expected_calls'], true))) {
                    throw new RuntimeException('reviewed_conformance_service_evidence_required');
                }
                foreach ($scenario['expected_calls'] as $call) {
                    if (! is_string($call) || ! in_array($call, HostContract::read()['runtime']['host_services'], true)) {
                        throw new RuntimeException('reviewed_conformance_scenarios_invalid');
                    }
                }
            }
            if (array_intersect(['success', 'remote_acceptance'], array_column($scenarios, 'kind')) === []) {
                throw new RuntimeException('reviewed_conformance_positive_case_required');
            }
            if (in_array($operation, ['configuration.validate', 'orders.validate', 'orders.submit', 'inbound.decode'], true) && ! in_array('validation_rejection', array_column($scenarios, 'kind'), true)) {
                throw new RuntimeException('reviewed_conformance_rejection_required');
            }
        }
        $readiness = $profile['scenarios']['identity.describe'][0];
        if ($readiness['kind'] !== 'success' || $readiness['input'] !== [] || $readiness['expected_calls'] !== []) {
            throw new RuntimeException('reviewed_conformance_readiness_invalid');
        }

        return $profile;
    }

    public static function fingerprint(array $profile): string
    {
        return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR));
    }
}
