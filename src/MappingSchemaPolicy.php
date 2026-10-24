<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use stdClass;

/** Mapping field restrictions exported by the actual host, separate from generic resource/wire schemas. */
final class MappingSchemaPolicy
{
    public static function assertSafe(stdClass $schema, array $credentialFields, string $resourceKey): void
    {
        $policy = HostContract::read()['manifest']['mapping_schema_policy'] ?? null;
        $keys = ['version', 'resource_keys', 'reserved_fields', 'sensitive_field_pattern', 'case_insensitive', 'credential_field_names_forbidden', 'recursive'];
        if (! is_array($policy) || array_diff($keys, array_keys($policy)) !== [] || array_diff(array_keys($policy), $keys) !== []
            || $policy['version'] !== 1 || ! is_array($policy['resource_keys']) || ! is_array($policy['reserved_fields'])
            || ! is_string($policy['sensitive_field_pattern']) || $policy['case_insensitive'] !== true
            || $policy['credential_field_names_forbidden'] !== true || $policy['recursive'] !== true) {
            throw new InvalidArgumentException('mapping_schema_policy_unavailable');
        }
        if (! in_array($resourceKey, $policy['resource_keys'], true)) {
            return;
        }
        self::fields($schema, $credentialFields, $resourceKey, $policy);
    }

    private static function fields(stdClass $schema, array $credentialFields, string $path, array $policy): void
    {
        foreach (get_object_vars($schema->properties ?? new stdClass) as $key => $field) {
            $fieldPath = $path.'.'.$key;
            if (in_array($key, $policy['reserved_fields'], true) || in_array($key, $credentialFields, true)
                || preg_match('/'.$policy['sensitive_field_pattern'].'/i', $key) === 1) {
                throw new InvalidArgumentException('mapping_schema_field_forbidden: '.$fieldPath.'. Put secrets in credential_schema; mapping fields hold product or service identifiers.');
            }
            self::fields($field, $credentialFields, $fieldPath, $policy);
        }
        if (($schema->items ?? null) instanceof stdClass) {
            self::fields($schema->items, $credentialFields, $path.'[]', $policy);
        }
    }
}
