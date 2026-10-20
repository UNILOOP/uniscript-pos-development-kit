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
    'orders.submit' => static function (Invocation $invocation, Services $services): Result {
        if (!isset($invocation->input()['order_id'])) { return new Result('validation_rejection', 'order.invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]); }
        $response = $services->request('http', 'request', ['endpoint_profile' => 'synthetic-pos', 'method' => 'POST', 'path' => '/orders', 'idempotency_key' => $invocation->idempotencyKey(), 'body' => $invocation->input()]);
        if ($response['status'] === 'unknown') { return new Result('unknown_remote_outcome', 'order.acceptance_unknown', [], [], ['action' => 'reconcile', 'resubmit_allowed' => false]); }
        return new Result('remote_acceptance', 'order.accepted', ['external_reference_handle' => $response['external_reference_handle']]);
    },
    'orders.reconcile' => static function (Invocation $invocation, Services $services): Result {
        $response = $services->request('http', 'request', ['endpoint_profile' => 'synthetic-pos', 'method' => 'GET', 'path' => '/orders/reconcile', 'idempotency_key' => $invocation->idempotencyKey()]);
        return $response['status'] === 'accepted' ? new Result('remote_acceptance', 'order.reconciled', ['external_reference_handle' => $response['external_reference_handle']]) : new Result('unknown_remote_outcome', 'order.not_observed', [], [], ['action' => 'operator_review', 'resubmit_allowed' => false]);
    },
    'inbound.decode' => static function (Invocation $invocation, Services $services): Result {
        $input = $invocation->input();
        if (($input['authenticated_receipt'] ?? false) !== true || !is_string($input['receipt_handle'] ?? null)) { return new Result('validation_rejection', 'callback.unauthenticated'); }
        return new Result('success', 'callback.proposed', $services->request('commands', 'propose', ['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event_id' => $input['event_id'] ?? null, 'status' => 'received']));
    },
    'catalog.preview' => static fn(Invocation $invocation, Services $services): Result => new Result('success', 'catalog.preview', ['proposals' => $services->request('resources', 'read', ['resource' => 'catalog'])['records'], 'applied' => false]),
    'reference.refresh' => static fn(Invocation $invocation, Services $services): Result => new Result('success', 'reference.refreshed', $services->request('storage', 'write', ['key' => 'reference', 'expected_revision' => $invocation->input()['expected_revision'] ?? 0, 'value' => ['synthetic' => true, 'values' => ['ground', 'overnight']]])),
];
