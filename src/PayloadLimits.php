<?php

declare(strict_types=1);

namespace UniScript\Integration;

/** Host defaults, raised or lowered only by the budgets a package's reviewed release declares. */
final class PayloadLimits
{
    /** @var array<string, array<string, int>> */
    private static array $declared = [];

    /** Record the `definitions.limits` of a package's host-requirements.json; invalid declarations are rejected. */
    public static function declare(string $packageId, mixed $limits): void
    {
        $rule = HostContract::read()['limits']['payload_profiles']['release_declared'];
        if (! is_array($limits) || $limits === [] || array_diff(array_keys($limits), $rule['fields']) !== []) {
            throw new \InvalidArgumentException('host_requirements_limits');
        }
        foreach ($limits as $bytes) {
            if (! is_int($bytes) || $bytes < $rule['minimum_bytes'] || $bytes > $rule['maximum_bytes']) {
                throw new \InvalidArgumentException('host_requirements_limits');
            }
        }
        self::$declared[$packageId] = $limits;
    }

    public static function forPackage(string $packageId): int
    {
        return self::size($packageId, 'invocation_bytes');
    }

    public static function serviceRequestForPackage(string $packageId): int
    {
        return self::size($packageId, 'service_request_bytes');
    }

    private static function size(string $packageId, string $field): int
    {
        $profiles = HostContract::read()['limits']['payload_profiles'];
        $value = $profiles['package_overrides'][$packageId][$field] ?? self::$declared[$packageId][$field] ?? $profiles['default'][$field] ?? null;
        if (! is_int($value) || $value < 1 || $value > 12582912) {
            throw new \InvalidArgumentException('host_payload_budget_invalid');
        }

        return $value;
    }
}
