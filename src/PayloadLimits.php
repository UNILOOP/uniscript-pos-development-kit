<?php

declare(strict_types=1);

namespace UniScript\Integration;

/** Larger document frames are limited to the independently approved API v2 package. */
final class PayloadLimits
{
    public static function forPackage(string $packageId): int
    {
        return $packageId === 'uniscript/lifefile-v2' ? 12582912 : 1048576;
    }
}
