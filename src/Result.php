<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Result implements JsonSerializable
{
    public const array KINDS = ['success', 'remote_acceptance', 'validation_rejection', 'unsupported_operation', 'temporary_failure', 'permanent_failure', 'unknown_remote_outcome', 'partial'];

    public function __construct(public string $kind, public string $reasonCode, public array $data = [], public array $fieldErrors = [], public array $recovery = [])
    {
        if (!in_array($kind, self::KINDS, true) || !preg_match('/\A[a-z][a-z0-9_.]+\z/D', $reasonCode)) {
            throw new InvalidArgumentException('invalid_result');
        }
        if (strlen(json_encode([$data, $fieldErrors, $recovery], JSON_THROW_ON_ERROR)) > 1048576) {
            throw new InvalidArgumentException('result_too_large');
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['protocol_version', 'kind', 'reason_code', 'data', 'field_errors', 'recovery']) !== [] || ($data['protocol_version'] ?? null) !== '1') {
            throw new InvalidArgumentException('invalid_result_envelope');
        }
        return new self($data['kind'], $data['reason_code'], $data['data'] ?? [], $data['field_errors'] ?? [], $data['recovery'] ?? []);
    }

    public function jsonSerialize(): array
    {
        return ['protocol_version' => '1', 'kind' => $this->kind, 'reason_code' => $this->reasonCode, 'data' => $this->data, 'field_errors' => $this->fieldErrors, 'recovery' => $this->recovery];
    }
}
