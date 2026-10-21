<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Result implements JsonSerializable
{
    public const array KINDS = ['success', 'remote_acceptance', 'validation_rejection', 'unsupported_operation', 'temporary_failure', 'permanent_failure', 'unknown_remote_outcome', 'partial'];

    public function __construct(public string $kind, public string $reasonCode, public array $data = [], public array $fieldErrors = [], public array $recovery = [], int $maxBytes = 1048576)
    {
        if (!in_array($kind, self::KINDS, true) || (!preg_match('/\A[a-z][a-z0-9_.]+\z/D', $reasonCode)||strlen($reasonCode)>100)) {
            throw new InvalidArgumentException('invalid_result');
        }
        JsonValue::assert([$data,$fieldErrors,$recovery]);
        if(!JsonValue::object($data)||!JsonValue::object($recovery)||!array_is_list($fieldErrors)||count($fieldErrors)>100){throw new InvalidArgumentException('invalid_result_shapes');}
        foreach($fieldErrors as $error){
            if(!is_array($error)||array_diff(array_keys($error),['path','reason_code','message_parameters'])!==[]||!is_string($error['path']??null)||strlen($error['path'])>240||!is_string($error['reason_code']??null)||!preg_match('/\A[a-z][a-z0-9_.]*\z/D',$error['reason_code'])||(array_key_exists('message_parameters',$error)&&(!is_array($error['message_parameters'])||!JsonValue::object($error['message_parameters'])))){throw new InvalidArgumentException('invalid_field_error');}
        }
        if(array_diff(array_keys($recovery),['action','resubmit_allowed','retry_after_seconds','work_item_handle'])!==[]||(array_key_exists('action',$recovery)&&(!is_string($recovery['action'])||!in_array($recovery['action'],['none','retry','reconcile','operator_review','fix_configuration','fix_mapping'],true)))||(array_key_exists('resubmit_allowed',$recovery)&&!is_bool($recovery['resubmit_allowed']))||(array_key_exists('retry_after_seconds',$recovery)&&(!is_int($recovery['retry_after_seconds'])||$recovery['retry_after_seconds']<0))||(array_key_exists('work_item_handle',$recovery)&&!is_string($recovery['work_item_handle']))){throw new InvalidArgumentException('invalid_recovery');}
        if($kind==='unknown_remote_outcome'&&(($recovery['resubmit_allowed']??null)!==false||!in_array($recovery['action']??null,['reconcile','operator_review'],true))){throw new InvalidArgumentException('unknown_outcome_must_not_resubmit');}
        if (strlen(json_encode([$data, $fieldErrors, $recovery], JSON_THROW_ON_ERROR)) > min(12582912, max(1, $maxBytes))) {
            throw new InvalidArgumentException('result_too_large');
        }
    }

    public static function fromArray(array $data, int $maxBytes = 1048576): self
    {
        if (array_diff(['protocol_version','kind','reason_code','data','field_errors','recovery'],array_keys($data))!==[] || array_diff(array_keys($data), ['protocol_version', 'kind', 'reason_code', 'data', 'field_errors', 'recovery']) !== [] || ($data['protocol_version'] ?? null) !== '1') {
            throw new InvalidArgumentException('invalid_result_envelope');
        }
        return new self($data['kind'], $data['reason_code'], $data['data'] ?? [], $data['field_errors'] ?? [], $data['recovery'] ?? [], $maxBytes);
    }

    public function jsonSerialize(): array
    {
        return ['protocol_version' => '1', 'kind' => $this->kind, 'reason_code' => $this->reasonCode, 'data' => $this->data, 'field_errors' => $this->fieldErrors, 'recovery' => $this->recovery];
    }
}
