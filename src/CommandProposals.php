<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use JsonException;

/** Shapes only: proposal receipt authentication, deduplication and application remain host-owned. */
final class CommandProposals
{
    /** @param array<string, mixed> $arguments */
    public static function normalizedEvent(array $arguments): array
    {
        $definition = HostContract::read()['command_proposals']['normalized_event'];
        if (array_diff(array_keys($arguments), $definition['argument_fields']) !== []
            || ($arguments['command'] ?? null) !== $definition['command']
            || ! is_string($arguments['receipt_handle'] ?? null) || trim($arguments['receipt_handle']) === ''
            || mb_strlen($arguments['receipt_handle']) > $definition['receipt_handle_max_length']) {
            throw new InvalidArgumentException('normalized_event_arguments_invalid');
        }
        if (array_key_exists('event_id', $arguments) && ! self::nullableString($arguments['event_id'], $definition['event_id_max_length'])) {
            throw new InvalidArgumentException('normalized_event_id_invalid');
        }
        if (array_key_exists('status', $arguments) && $arguments['status'] !== '' && ! in_array($arguments['status'], $definition['status_values'], true)) {
            throw new InvalidArgumentException('normalized_event_status_invalid');
        }
        if (array_key_exists('event', $arguments)) {
            $event = $arguments['event'];
            if (! is_array($event) || array_diff(array_keys($event), [...$definition['event_string_fields'], ...$definition['event_boolean_fields']]) !== []) {
                throw new InvalidArgumentException('normalized_event_fields_invalid');
            }
            foreach ($event as $field => $value) {
                if (in_array($field, $definition['event_string_fields'], true)) {
                    if (! self::nullableString($value, $definition['event_string_max_length'])) {
                        throw new InvalidArgumentException('normalized_event_string_invalid');
                    }
                } elseif ($value !== '' && ! in_array($value, $definition['boolean_values'], true)) {
                    throw new InvalidArgumentException('normalized_event_boolean_invalid');
                }
            }
        }
        try {
            $json = json_encode($arguments, JSON_THROW_ON_ERROR, 32);
        } catch (JsonException) {
            throw new InvalidArgumentException('normalized_event_json_invalid');
        }
        if (strlen($json) > $definition['maximum_bytes']) {
            throw new InvalidArgumentException('normalized_event_size_exceeded');
        }

        return $arguments;
    }

    private static function nullableString(mixed $value, int $maximum): bool
    {
        return $value === null || (is_string($value) && mb_strlen($value) <= $maximum);
    }
}
