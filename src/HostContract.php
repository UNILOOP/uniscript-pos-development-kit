<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

/** Public, versioned host contract; it grants no transport, tenant or release authority. */
final class HostContract
{
    /** @return array<string, mixed> */
    public static function read(?string $path = null): array
    {
        $path ??= dirname(__DIR__).'/contracts/host-v1.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 262144) {
            throw new InvalidArgumentException('host_contract_unreadable');
        }
        $contract = JsonDocument::decode(file_get_contents($path), true);
        self::assertDefinition($contract);

        return $contract;
    }

    /** @param array<string, mixed> $contract */
    public static function assertDefinition(array $contract): void
    {
        $keys = ['contract_version', 'manifest_versions', 'runtime', 'manifest', 'limits', 'ui', 'projection_fields', 'command_proposals', 'onboarding'];
        if (array_diff($keys, array_keys($contract)) !== [] || array_diff(array_keys($contract), $keys) !== [] || ($contract['contract_version'] ?? null) !== '1.0.0') {
            throw new InvalidArgumentException('host_contract_version_or_fields');
        }
        $proposal = $contract['command_proposals']['normalized_event'] ?? null;
        if (! is_array($proposal) || ($proposal['command'] ?? null) !== 'normalized_event'
            || ($proposal['operation'] ?? null) !== 'inbound.decode' || ($proposal['authenticated_receipt_required'] ?? null) !== true
            || ($proposal['maximum_bytes'] ?? null) !== 32768 || ! is_array($proposal['event_string_fields'] ?? null)
            || ! is_array($proposal['event_boolean_fields'] ?? null) || ! is_array($proposal['argument_fields'] ?? null)) {
            throw new InvalidArgumentException('host_contract_command_proposals');
        }
        $surfaces = $contract['ui']['surfaces'] ?? null;
        if (! is_array($surfaces) || array_keys($surfaces) !== ['connection.setup', 'catalog.item.mapping', 'pharmacy.order.details', 'sender.order.details']) {
            throw new InvalidArgumentException('host_contract_surfaces');
        }
        foreach ($surfaces as $surface => $operations) {
            if (! is_array($operations) || array_keys($operations) !== ['queries', 'actions']) {
                throw new InvalidArgumentException('host_contract_surface_operations');
            }
            foreach ($operations as $list) {
                if (! is_array($list) || ! array_is_list($list) || count(array_unique($list, SORT_REGULAR)) !== count($list)) {
                    throw new InvalidArgumentException('host_contract_operation_list');
                }
                foreach ($list as $operation) {
                    if (! is_string($operation) || ! preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]*)+\z/D', $operation)) {
                        throw new InvalidArgumentException('host_contract_operation');
                    }
                }
            }
            if (array_intersect($operations['queries'], $operations['actions']) !== [] || ! in_array('context.read', $operations['queries'], true)
                || ($surface === 'sender.order.details' && $operations['actions'] !== [])) {
                throw new InvalidArgumentException('host_contract_surface_authority');
            }
        }
        if (($contract['ui']['credential_controls_host_owned'] ?? null) !== true || ($contract['ui']['separate_execution_origin'] ?? null) !== true
            || ! is_string($contract['ui']['asset_path_pattern'] ?? null) || strlen($contract['ui']['asset_path_pattern']) > 1024
            || ($contract['ui']['asset_path_max_bytes'] ?? null) !== 240
            || ($contract['onboarding']['local_scenarios_authorize_vendor_access'] ?? null) !== false
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $contract['ui']['bridge_sha256'] ?? '')) {
            throw new InvalidArgumentException('host_contract_trust_boundary');
        }
    }

    /** @return array{queries: list<string>, actions: list<string>} */
    public static function surface(string $surface): array
    {
        return self::read()['ui']['surfaces'][$surface] ?? throw new InvalidArgumentException('unsupported_package_ui_surface');
    }

    /** @return list<string> */
    public static function operations(): array
    {
        $operations = [];
        foreach (self::read()['ui']['surfaces'] as $surface) {
            $operations = [...$operations, ...$surface['queries'], ...$surface['actions']];
        }

        return array_values(array_unique($operations));
    }

    /** @param array<string, mixed> $actual */
    public static function assertCompatible(array $actual): void
    {
        self::assertDefinition($actual);
        if ($actual !== self::read()) {
            throw new InvalidArgumentException('host_contract_drift');
        }
    }

    public static function digest(): string
    {
        return hash('sha256', json_encode(self::read(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array{value: string, label: string}> */
    public static function timezones(): array
    {
        $options = JsonDocument::decode(file_get_contents(dirname(__DIR__).'/contracts/timezones.json'), true);
        if (! array_is_list($options) || ! hash_equals(self::read()['ui']['timezones_sha256'], hash('sha256', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
            throw new InvalidArgumentException('host_timezone_contract_drift');
        }

        return $options;
    }

    /** Validate the redacted output of a public bridge query or action. @param array<string, mixed> $projection */
    public static function assertProjection(string $operation, array $projection): void
    {
        if (! in_array($operation, self::operations(), true)) {
            throw new InvalidArgumentException('unsupported_package_ui_operation');
        }
        (new SchemaValidator(dirname(__DIR__)))->validate($projection, 'contracts/projections/'.$operation.'.json');
    }
}
