<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

final class Manifest
{
    public static function read(string $root): array
    {
        $json = file_get_contents($root.'/integration.json');
        if ($json === false || strlen($json) > 65536) { throw new InvalidArgumentException('manifest_unreadable'); }
        $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $keys = ['manifest_version', 'package_id', 'publisher', 'integration_key', 'display_name', 'vendor_family', 'version', 'sdk_version', 'protocol_version', 'runtime', 'operations', 'capabilities', 'support'];
        if (!is_array($manifest) || array_diff($keys, array_keys($manifest)) !== [] || array_diff(array_keys($manifest), $keys) !== []) { throw new InvalidArgumentException('manifest_fields'); }
        if ($manifest['manifest_version'] !== 1 || $manifest['protocol_version'] !== '1' || $manifest['sdk_version'] !== '^1.0' || !preg_match('/\A[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*\z/D', $manifest['package_id']) || !str_starts_with($manifest['package_id'], $manifest['publisher'].'/') || !preg_match('/\A\d+\.\d+\.\d+\z/D', $manifest['version'])) { throw new InvalidArgumentException('manifest_identity'); }
        if (($manifest['runtime']['php'] ?? null) !== '^8.4' || ($manifest['runtime']['profile'] ?? null) !== 'standard') { throw new InvalidArgumentException('manifest_runtime'); }
        self::path($manifest['runtime']['entrypoint']);
        if (!is_file($root.'/'.$manifest['runtime']['entrypoint'])) { throw new InvalidArgumentException('missing_entrypoint'); }
        $seen = [];
        foreach ($manifest['operations'] as $operation) {
            if (!is_string($operation['key'] ?? null) || isset($seen[$operation['key']]) || !preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]*)+\z/D', $operation['key'])) { throw new InvalidArgumentException('invalid_operation'); }
            $seen[$operation['key']] = true;
            foreach (['input_schema', 'output_schema'] as $field) {
                self::path($operation[$field]);
                if (!is_file($root.'/'.$operation[$field])) { throw new InvalidArgumentException('missing_schema'); }
                json_decode(file_get_contents($root.'/'.$operation[$field]), true, 32, JSON_THROW_ON_ERROR);
            }
        }
        foreach (['composer.json', 'composer.lock'] as $file) {
            json_decode(file_get_contents($root.'/'.$file), true, 32, JSON_THROW_ON_ERROR);
        }
        return $manifest;
    }

    public static function path(string $path): void
    {
        if (strlen($path) > 240 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*\z/D', $path)) { throw new InvalidArgumentException('unsafe_path'); }
        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['.', '..'], true) || str_ends_with($part, '.') || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $part)) { throw new InvalidArgumentException('unsafe_path'); }
        }
    }
}
