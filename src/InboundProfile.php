<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

/** Operator-owned callback wire description. Uploaded packages cannot grant endpoint authority. */
final class InboundProfile
{
    public static function validate(array $profile, string $packageId): array
    {
        $keys = ['version', 'authentication', 'label', 'reference_field', 'event_id_field', 'account_fields', 'package_ids'];
        if (array_diff(array_keys($profile), $keys) !== [] || array_diff($keys, array_keys($profile)) !== []
            || $profile['version'] !== 1 || $profile['authentication'] !== 'basic'
            || ! is_string($profile['label']) || strlen($profile['label']) > 100
            || ! is_array($profile['package_ids']) || ! array_is_list($profile['package_ids']) || ! in_array($packageId, $profile['package_ids'], true)
            || ! self::field($profile['reference_field']) || ! self::field($profile['event_id_field'])
            || ! is_array($profile['account_fields']) || count($profile['account_fields']) > 5) {
            throw new InvalidArgumentException('inbound_profile_invalid');
        }
        foreach ($profile['account_fields'] as $source => $configuration) {
            if (! self::field($source) || ! self::field($configuration)) {
                throw new InvalidArgumentException('inbound_profile_account_field_invalid');
            }
        }

        return $profile;
    }

    public static function value(array $values, string $path): mixed
    {
        $value = $values;
        foreach (explode('.', $path) as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    public static function set(array &$values, string $path, mixed $value): void
    {
        $target = &$values;
        $segments = explode('.', $path);
        $last = array_pop($segments);
        foreach ($segments as $key) {
            if (! is_array($target[$key] ?? null)) {
                $target[$key] = [];
            }
            $target = &$target[$key];
        }
        $target[$last] = $value;
    }

    private static function field(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z][A-Za-z0-9_.]{0,99}\z/D', $value) === 1;
    }
}
