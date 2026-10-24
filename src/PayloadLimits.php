<?php

declare(strict_types=1);

namespace UniScript\Integration;

/** Larger document frames are limited to the independently approved API v2 package. */
final class PayloadLimits
{
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
        $value = $profiles['package_overrides'][$packageId][$field] ?? $profiles['default'][$field] ?? null;
        if (! is_int($value) || $value < 1 || $value > 12582912) {
            throw new \InvalidArgumentException('host_payload_budget_invalid');
        }

        return $value;
    }
}
