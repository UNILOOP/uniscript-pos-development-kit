<?php

declare(strict_types=1);

namespace UniScript\Integration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Invocation
{
    private function __construct(public array $envelope) {}

    public static function fromArray(array $envelope): self
    {
        if (($envelope['protocol_version'] ?? null) !== '1') {
            throw new InvalidArgumentException('unsupported_protocol');
        }
        foreach (['invocation_id', 'command_id', 'attempt_id', 'correlation_id', 'package_id', 'execution_revision', 'connection_id', 'organization_id', 'location_id', 'environment', 'operation', 'schema_version', 'routing_revision', 'configuration_revision', 'mapping_revision', 'credential_revision', 'idempotency_key', 'deadline', 'actor_id'] as $field) {
            if (!is_string($envelope[$field] ?? null) || $envelope[$field] === '' || strlen($envelope[$field]) > 240) {
                throw new InvalidArgumentException('invalid_envelope:'.$field);
            }
        }
        if (!in_array($envelope['environment'], ['local', 'sandbox', 'dev', 'production'], true)) {
            throw new InvalidArgumentException('invalid_environment');
        }
        if (!preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z\z/D', $envelope['deadline'])) {
            throw new InvalidArgumentException('invalid_deadline');
        }
        $deadline = new DateTimeImmutable($envelope['deadline']);
        if ($deadline <= new DateTimeImmutable()) {
            throw new InvalidArgumentException('deadline_expired');
        }
        if (!is_int($envelope['request_budget'] ?? null) || $envelope['request_budget'] < 0 || $envelope['request_budget'] > 100) {
            throw new InvalidArgumentException('invalid_request_budget');
        }
        if (isset($envelope['deployment_environment']) && !in_array($envelope['deployment_environment'], ['local', 'dev'], true)) {
            throw new InvalidArgumentException('invalid_deployment_environment');
        }
        if (!is_array($envelope['input'] ?? null) || !is_string($envelope['service_grant'] ?? null) || $envelope['service_grant'] === '') {
            throw new InvalidArgumentException('invalid_input_or_grant');
        }
        return new self($envelope);
    }

    public function operation(): string { return $this->envelope['operation']; }
    public function input(): array { return $this->envelope['input']; }
    public function idempotencyKey(): string { return $this->envelope['idempotency_key']; }
}
