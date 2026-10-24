<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

/** Requests existing host profile names; it cannot define or approve their authority. */
final class HostRequirements
{
    public const int MAXIMUM_BYTES = 16384;

    public static function read(string $root): ?array
    {
        $path = $root.'/host-requirements.json';
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        if (is_link($path) || !is_file($path) || filesize($path) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('host_requirements_file');
        }

        return self::parse(file_get_contents($path));
    }

    public static function parse(string $json): array
    {
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('host_requirements_limit');
        }
        $structure = JsonDocument::decode($json);
        if (!$structure instanceof \stdClass || !is_array($structure->transport_profiles ?? null) || !is_array($structure->inbound_profiles ?? null)) {
            throw new InvalidArgumentException('host_requirements_json_shapes');
        }
        $requirements = JsonDocument::decode($json, true);
        $keys = ['contract_version', 'transport_profiles', 'verification_profile', 'inbound_profiles'];
        if (!is_array($requirements) || array_diff($keys, array_keys($requirements)) !== [] || array_diff(array_keys($requirements), $keys) !== [] || ($requirements['contract_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('host_requirements_fields');
        }
        foreach (['transport_profiles', 'inbound_profiles'] as $field) {
            if (!is_array($requirements[$field]) || !array_is_list($requirements[$field]) || count($requirements[$field]) > 32) {
                throw new InvalidArgumentException('host_requirements_profiles');
            }
            foreach ($requirements[$field] as $name) {
                self::assertName($name);
            }
            if (count(array_unique($requirements[$field])) !== count($requirements[$field])) {
                throw new InvalidArgumentException('host_requirements_duplicate');
            }
        }
        if ($requirements['verification_profile'] !== null) {
            self::assertName($requirements['verification_profile']);
        }

        return $requirements;
    }

    private static function assertName(mixed $name): void
    {
        if (!is_string($name) || preg_match('/\A[a-z][a-z0-9._-]{0,79}\z/D', $name) !== 1) {
            throw new InvalidArgumentException('host_requirements_profile_name');
        }
    }
}
