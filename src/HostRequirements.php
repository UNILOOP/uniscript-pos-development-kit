<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

/** Packaging metadata only. Internal host approval remains the authority for release declarations. */
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
        $version = $requirements['contract_version'] ?? null;
        $keys = ['contract_version', 'transport_profiles', 'verification_profile', 'inbound_profiles', ...($version === 2 ? ['package_id', 'definitions'] : [])];
        if (!is_array($requirements) || array_diff($keys, array_keys($requirements)) !== [] || array_diff(array_keys($requirements), $keys) !== [] || !in_array($version, [1, 2], true)) {
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

        if ($version === 2) {
            $id = $requirements['package_id'];
            $definitions = $requirements['definitions'];
            if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*\z/D', $id) || !is_array($definitions)
                || array_diff(array_keys($definitions), ['transport', 'verification', 'inbound', 'policy', 'limits']) !== []) {
                throw new InvalidArgumentException('host_requirements_definitions');
            }
            if (array_key_exists('limits', $definitions)) {
                PayloadLimits::declare($id, $definitions['limits']);
            }
            foreach (['transport', 'verification', 'inbound'] as $kind) {
                if (!is_array($definitions[$kind] ?? null) || count($definitions[$kind]) > 32) {
                    throw new InvalidArgumentException('host_requirements_definitions');
                }
                foreach ($definitions[$kind] as $name => $profile) {
                    self::assertName($name);
                    if (!is_array($profile) || ($profile['package_ids'] ?? null) !== [$id]) {
                        throw new InvalidArgumentException('host_requirements_definition_ownership');
                    }
                    if ($kind === 'transport' && (($profile['profile_version'] ?? null) !== 1 || isset($profile['body_rules'])
                        || ($profile['allow_local_synthetic'] ?? false) !== false || !is_string($profile['base_url'] ?? null)
                        || parse_url($profile['base_url'], PHP_URL_SCHEME) !== 'https')) {
                        throw new InvalidArgumentException('host_requirements_transport');
                    }
                    if ($kind === 'verification' && ($profile['synthetic_only'] ?? true) !== false) {
                        throw new InvalidArgumentException('host_requirements_verification');
                    }
                }
            }
            $policy = $definitions['policy'] ?? null;
            if (!is_array($policy) || array_diff(array_keys($policy), ['self_service', 'verification_profile']) !== []
                || !is_bool($policy['self_service'] ?? null) || !is_string($policy['verification_profile'] ?? null)
                || !isset($definitions['verification'][$policy['verification_profile']])) {
                throw new InvalidArgumentException('host_requirements_policy');
            }
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
