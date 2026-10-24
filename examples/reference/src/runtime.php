<?php

declare(strict_types=1);

use UniScript\Integration\ConfigurationSchema;
use UniScript\Integration\Invocation;
use UniScript\Integration\JsonDocument;
use UniScript\Integration\Result;
use UniScript\Integration\Services;

$configuration = static function (Invocation $invocation): Result {
    try {
        ConfigurationSchema::validateValues(JsonDocument::decode(file_get_contents(__DIR__.'/../schemas/configuration.json')), $invocation->input()['config'] ?? []);
        if (! in_array($invocation->input()['config']['timezone_name'], DateTimeZone::listIdentifiers(), true)) {
            return new Result('validation_rejection', 'reference.timezone_invalid');
        }

        return new Result('success', 'reference.configuration_valid');
    } catch (Throwable) {
        return new Result('validation_rejection', 'reference.configuration_invalid');
    }
};
$order = static function (Invocation $invocation): Result {
    $input = $invocation->input();
    if (! is_string($input['order_id'] ?? null) || $input['order_id'] === '') {
        return new Result('validation_rejection', 'reference.order_invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]);
    }
    foreach ($input['order']['medications'] ?? [] as $medication) {
        if (in_array($medication['mapping']['schedule_code'] ?? $medication['schedule_code'] ?? 'O', ['2', '3', '4', '5'], true)) {
            return new Result('validation_rejection', 'reference.verified_controlled_payload_required');
        }
    }

    return new Result('success', 'reference.order_valid');
};
$http = static function (Invocation $invocation, Services $services, string $profile, ?array $body = null): Result {
    $arguments = ['profile' => $profile, 'operation_key' => $invocation->operation(), 'parameters' => [], 'idempotency_key' => $invocation->idempotencyKey()];
    if (isset($invocation->input()['external_reference_handle'])) {
        $arguments['parameters']['external_reference_handle'] = $invocation->input()['external_reference_handle'];
    }
    if ($body !== null) {
        $arguments['body'] = $body;
    }
    try {
        $response = $services->request('http', 'request', $arguments);
    } catch (Throwable) {
        return str_starts_with($invocation->operation(), 'orders.')
            ? new Result('unknown_remote_outcome', 'reference.transport_unknown', [], [], ['action' => 'reconcile', 'resubmit_allowed' => false])
            : new Result('temporary_failure', 'reference.probe_unavailable', [], [], ['action' => 'retry', 'resubmit_allowed' => false]);
    }
    if (($response['status'] ?? null) === 'accepted') {
        if (str_starts_with($invocation->operation(), 'orders.')) {
            if (! is_string($response['external_reference_handle'] ?? null)) {
                return new Result('unknown_remote_outcome', 'reference.acceptance_unknown', [], [], ['action' => 'reconcile', 'resubmit_allowed' => false]);
            }

            return new Result('remote_acceptance', 'reference.order_accepted', ['external_reference_handle' => $response['external_reference_handle']]);
        }

        return new Result('success', 'reference.probe_succeeded', ['vendor_connectivity_verified' => true, 'response' => $response['data'] ?? []]);
    }
    if (($response['status'] ?? null) === 'rejected') {
        if (in_array($invocation->operation(), ['health.probe', 'connection.test'], true)) {
            return new Result('temporary_failure', 'reference.probe_rejected', [], [], ['action' => 'retry', 'resubmit_allowed' => false]);
        }

        return new Result('permanent_failure', 'reference.remote_rejected');
    }
    if (($response['status'] ?? null) === 'temporary_failure') {
        return new Result('temporary_failure', 'reference.vendor_rate_limited', [], [], ['action' => 'retry', 'resubmit_allowed' => false, 'retry_after_seconds' => $response['retry_after_seconds'] ?? 60]);
    }

    return new Result('unknown_remote_outcome', 'reference.acceptance_unknown', [], [], ['action' => 'reconcile', 'resubmit_allowed' => false]);
};

return [
    'identity.describe' => static fn (Invocation $invocation, Services $services): Result => new Result('success', 'reference.identity_available', ['package_id' => $invocation->envelope['package_id'], 'version' => JsonDocument::decode(file_get_contents(__DIR__.'/../integration.json'), true)['version']]),
    'configuration.validate' => static fn (Invocation $invocation, Services $services): Result => $configuration($invocation),
    'health.probe' => static fn (Invocation $invocation, Services $services): Result => $configuration($invocation)->kind === 'success' ? $http($invocation, $services, 'synthetic.health') : $configuration($invocation),
    'connection.test' => static fn (Invocation $invocation, Services $services): Result => $configuration($invocation)->kind === 'success' ? $http($invocation, $services, 'synthetic.health') : $configuration($invocation),
    'orders.validate' => static fn (Invocation $invocation, Services $services): Result => $order($invocation),
    'orders.submit' => static fn (Invocation $invocation, Services $services): Result => $order($invocation)->kind === 'success' ? $http($invocation, $services, 'synthetic.orders', ['order_id' => $invocation->input()['order_id'], 'scenario' => $invocation->input()['scenario'] ?? 'accepted']) : $order($invocation),
    'orders.reconcile' => static fn (Invocation $invocation, Services $services): Result => $http($invocation, $services, 'synthetic.reconcile'),
    'orders.status.update' => static fn (Invocation $invocation, Services $services): Result => isset($invocation->input()['external_reference_handle'], $invocation->input()['status']) ? $http($invocation, $services, 'synthetic.status', ['status' => $invocation->input()['status']]) : new Result('validation_rejection', 'reference.action_invalid'),
    'orders.shipping.update' => static fn (Invocation $invocation, Services $services): Result => isset($invocation->input()['external_reference_handle'], $invocation->input()['shipping']) ? $http($invocation, $services, 'synthetic.shipping', ['shipping' => $invocation->input()['shipping']]) : new Result('validation_rejection', 'reference.action_invalid'),
    'catalog.preview' => static function (Invocation $invocation, Services $services): Result {
        $records = $invocation->input()['records'] ?? null;
        if ($records === null) {
            $records = $services->request('resources', 'read', ['resource' => 'catalog'])['records'] ?? [];
        }
        if (! is_array($records) || ! array_is_list($records) || count($records) > 1000) {
            return new Result('validation_rejection', 'reference.catalog_invalid');
        }
        $proposals = [];
        foreach ($records as $record) {
            if (! is_string($record['source_key'] ?? null) || ! is_string($record['display_name'] ?? null) || ! is_string($record['product_code'] ?? null) || ! in_array($record['schedule_code'] ?? null, ['O', 'L', '2', '3', '4', '5'], true)) {
                return new Result('validation_rejection', 'reference.catalog_row_invalid');
            }
            $proposals[] = ['source_key' => $record['source_key'], 'display_name' => $record['display_name'], 'canonical_item_handle' => $record['canonical_item_handle'] ?? null, 'mapping_values' => ['product_code' => $record['product_code'], 'schedule_code' => $record['schedule_code']], 'warnings' => []];
        }

        return new Result('success', 'reference.catalog_preview', ['proposals' => $proposals, 'applied' => false]);
    },
    'reference.refresh' => static fn (Invocation $invocation, Services $services): Result => new Result('success', 'reference.refreshed', $services->request('storage', 'write', ['key' => 'reference', 'expected_revision' => $invocation->input()['expected_revision'] ?? 0, 'value' => ['values' => ['ground', 'overnight']]])),
    'reference.inspect' => static fn (Invocation $invocation, Services $services): Result => new Result('success', 'reference.inspected', $services->request('storage', 'read', ['key' => 'reference'])),
    'inbound.decode' => static function (Invocation $invocation, Services $services): Result {
        $input = $invocation->input();
        if (($input['authenticated_receipt'] ?? null) !== true || ! is_string($input['receipt_handle'] ?? null) || ! is_array($input['events'] ?? null) || ! array_is_list($input['events']) || count($input['events']) > 100) {
            return new Result('validation_rejection', 'reference.authenticated_receipt_required');
        }
        foreach ($input['events'] as $event) {
            if (! is_array($event) || ! is_string($event['orderId'] ?? null) || ! is_string($event['eventId'] ?? null) || ! in_array($event['status'] ?? null, ['received', 'queued', 'in_progress', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'], true)) {
                return new Result('validation_rejection', 'reference.event_invalid');
            }
        }
        $proposals = [];
        foreach ($input['events'] as $event) {
            $status = $event['status'];
            $proposal = ['external_order_reference' => $event['orderId'], 'event_id' => $event['eventId'], 'normalized_status' => $status, 'order_status' => ['shipped' => 'ready', 'delivered' => 'completed'][$status] ?? $status, 'tracking_number' => $event['trackingNumber'] ?? null, 'carrier' => $event['carrier'] ?? null, 'occurred_at' => $event['occurred_at'] ?? null];
            $proposals[] = $services->request('commands', 'propose', ['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event' => $proposal]);
        }

        return new Result('success', 'reference.callback_proposed', ['proposals' => $proposals, 'applied' => false]);
    },
];
