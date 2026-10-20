<?php

declare(strict_types=1);

use UniScript\Integration\Invocation;
use UniScript\Integration\Result;
use UniScript\Integration\Services;

return [
    'identity.describe' => static fn(Invocation $invocation, Services $services): Result => new Result('success', 'identity.available', ['package_id' => $invocation->envelope['package_id']]),
    'health.probe' => static fn(Invocation $invocation, Services $services): Result => new Result('success', 'health.ready'),
    'configuration.validate' => static fn(Invocation $invocation, Services $services): Result => in_array($invocation->input()['mode'] ?? 'mock', ['mock'], true) ? new Result('success', 'configuration.valid') : new Result('validation_rejection', 'configuration.invalid', [], [['path' => 'mode', 'reason_code' => 'unsupported']]),
    'orders.validate' => static fn(Invocation $invocation, Services $services): Result => isset($invocation->input()['order_id']) ? new Result('success', 'order.valid') : new Result('validation_rejection', 'order.invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]),
    'orders.submit' => static fn(Invocation $invocation, Services $services): Result => isset($invocation->input()['order_id']) ? new Result('remote_acceptance', 'order.mock_accepted', ['external_reference_handle' => 'mock-'.substr(hash('sha256', $invocation->idempotencyKey()), 0, 16)]) : new Result('validation_rejection', 'order.invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]),
];
