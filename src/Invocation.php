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
        $required=['protocol_version','invocation_id','command_id','attempt_id','correlation_id','package_id','execution_revision','connection_id','organization_id','location_id','environment','operation','schema_version','routing_revision','configuration_revision','mapping_revision','credential_revision','idempotency_key','deadline','actor_id','request_budget','input','service_grant'];
        if(array_diff($required,array_keys($envelope))!==[]||array_diff(array_keys($envelope),[...$required,'deployment_environment'])!==[]){throw new InvalidArgumentException('invalid_envelope_fields');}
        JsonValue::assert($envelope);
        if(strlen(json_encode($envelope,JSON_THROW_ON_ERROR))>PayloadLimits::forPackage($envelope['package_id']??'')){throw new InvalidArgumentException('envelope_too_large');}
        if (($envelope['protocol_version'] ?? null) !== '1') {
            throw new InvalidArgumentException('unsupported_protocol');
        }
        foreach (['invocation_id', 'command_id', 'attempt_id', 'correlation_id', 'package_id', 'execution_revision', 'connection_id', 'organization_id', 'location_id', 'environment', 'operation', 'schema_version', 'routing_revision', 'configuration_revision', 'mapping_revision', 'credential_revision', 'idempotency_key', 'deadline', 'actor_id'] as $field) {
            if (!is_string($envelope[$field] ?? null) || $envelope[$field] === '' || strlen($envelope[$field]) > 240 || preg_match('/[\x00-\x20\x7f]/',$envelope[$field])) {
                throw new InvalidArgumentException('invalid_envelope:'.$field);
            }
        }
        if(!preg_match('/\A[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*\z/D',$envelope['package_id'])||!preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]*)+\z/D',$envelope['operation'])){throw new InvalidArgumentException('invalid_invocation_identity');}
        if (!in_array($envelope['environment'], ['local', 'sandbox', 'dev', 'production'], true)) {
            throw new InvalidArgumentException('invalid_environment');
        }
        if (!preg_match('/\A(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)(?:\.(\d{1,6}))?Z\z/D', $envelope['deadline'], $parts)) {
            throw new InvalidArgumentException('invalid_deadline');
        }
        if(!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])||(int)$parts[4]>23||(int)$parts[5]>59||(int)$parts[6]>59){throw new InvalidArgumentException('invalid_deadline');}
        $deadline = new DateTimeImmutable($envelope['deadline']);
        if ($deadline <= new DateTimeImmutable()) {
            throw new InvalidArgumentException('deadline_expired');
        }
        if (!is_int($envelope['request_budget'] ?? null) || $envelope['request_budget'] < 0 || $envelope['request_budget'] > 100) {
            throw new InvalidArgumentException('invalid_request_budget');
        }
        if (array_key_exists('deployment_environment',$envelope) && !in_array($envelope['deployment_environment'], ['local','dev','production'], true)) {
            throw new InvalidArgumentException('invalid_deployment_environment');
        }
        if ((!is_array($envelope['input'] ?? null)||!JsonValue::object($envelope['input'])) || !is_string($envelope['service_grant'] ?? null) || $envelope['service_grant'] === '' || strlen($envelope['service_grant'])>8192) {
            throw new InvalidArgumentException('invalid_input_or_grant');
        }
        return new self($envelope);
    }

    public function operation(): string { return $this->envelope['operation']; }
    public function input(): array { return $this->envelope['input']; }
    public function idempotencyKey(): string { return $this->envelope['idempotency_key']; }
}
