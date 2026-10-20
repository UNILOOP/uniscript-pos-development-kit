<?php

declare(strict_types=1);

use UniScript\Integration\Invocation;
use UniScript\Integration\Result;
use UniScript\Integration\Services;

$validateConfiguration=static function(Invocation $invocation, Services $services): Result {
        try {
            $schema=UniScript\Integration\JsonDocument::decode(file_get_contents(__DIR__.'/../schemas/configuration.json'));
            $configuration=$invocation->input()['config']??null;if(!is_array($configuration)){throw new InvalidArgumentException('configuration_object_required');}
            UniScript\Integration\ConfigurationSchema::validateValues($schema,$configuration);
            foreach(['vendor_id','location_id','api_network_id'] as $field){if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$configuration[$field])){throw new InvalidArgumentException('invalid_provider_identity');}}
            return new Result('success','configuration.valid');
        } catch(InvalidArgumentException) { return new Result('validation_rejection','configuration.invalid'); }
    };

return [
    'identity.describe' => static fn(Invocation $invocation, Services $services): Result => new Result('success', 'identity.available', ['package_id' => $invocation->envelope['package_id']]),
    'health.probe' => static function(Invocation $invocation, Services $services)use($validateConfiguration):Result{$validation=$validateConfiguration($invocation,$services);return $validation->kind==='success'?new Result('success','health.ready',['vendor_connectivity_verified'=>false]):$validation;},
    'configuration.validate' => $validateConfiguration,
    'orders.validate' => static fn(Invocation $invocation, Services $services): Result => (is_string($invocation->input()['order_id'] ?? null) && $invocation->input()['order_id'] !== '' && strlen($invocation->input()['order_id']) <= 200) ? new Result('success', 'order.valid') : new Result('validation_rejection', 'order.invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]),
    'orders.submit' => static function (Invocation $invocation, Services $services): Result {
        if (!is_string($invocation->input()['order_id'] ?? null) || $invocation->input()['order_id'] === '' || strlen($invocation->input()['order_id']) > 200) { return new Result('validation_rejection', 'order.invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]); }
        $body = ['order_id' => $invocation->input()['order_id']];
        if (array_key_exists('scenario', $invocation->input())) {
            $scenario = $invocation->input()['scenario'];
            if (! is_string($scenario) || strlen($scenario) > 64) { return new Result('validation_rejection', 'order.scenario_invalid'); }
            $body['scenario'] = $scenario;
        }
        $response = $services->request('http', 'request', ['profile' => 'synthetic.orders', 'operation_key' => $invocation->operation(), 'parameters' => [], 'idempotency_key' => $invocation->idempotencyKey(), 'body' => $body]);
        if ($response['status'] === 'unknown') { return new Result('unknown_remote_outcome', 'order.acceptance_unknown', [], [], ['action' => 'reconcile', 'resubmit_allowed' => false]); }
        return new Result('remote_acceptance', 'order.accepted', ['external_reference_handle' => $response['external_reference_handle']]);
    },
    'orders.reconcile' => static function (Invocation $invocation, Services $services): Result {
        $response = $services->request('http', 'request', ['profile' => 'synthetic.reconcile', 'operation_key' => $invocation->operation(), 'parameters' => [], 'idempotency_key' => $invocation->idempotencyKey()]);
        return $response['status'] === 'accepted' ? new Result('remote_acceptance', 'order.reconciled', ['external_reference_handle' => $response['external_reference_handle']]) : new Result('unknown_remote_outcome', 'order.not_observed', [], [], ['action' => 'operator_review', 'resubmit_allowed' => false]);
    },
    'inbound.decode' => static function (Invocation $invocation, Services $services): Result {
        $input = $invocation->input();
        if (($input['authenticated_receipt'] ?? false) !== true || ! is_string($input['receipt_handle'] ?? null) || $input['receipt_handle'] === '' || strlen($input['receipt_handle']) > 200) { return new Result('validation_rejection', 'callback.unauthenticated'); }
        $events = $input['events'] ?? null;
        if (! is_array($events) || ! array_is_list($events) || $events === [] || count($events) > 100) { return new Result('validation_rejection', 'callback.events_invalid'); }
        if (count($events) > $invocation->envelope['request_budget']) { return new Result('validation_rejection', 'callback.budget_exceeded'); }
        $normalized = [];
        foreach ($events as $source) {
            if (! is_array($source) || ! is_string($source['status'] ?? null) || strlen($source['status']) > 100) { return new Result('validation_rejection', 'callback.event_invalid'); }
            $event = [];
            foreach (['orderId' => 'external_order_reference', 'eventId' => 'event_id'] as $field => $target) {
                $value = $source[$field] ?? null;
                if ((! is_string($value) && ! is_int($value)) || preg_match('/\A[\x21-\x7e]{1,200}\z/D', (string) $value) !== 1) { return new Result('validation_rejection', 'callback.event_identity_invalid'); }
                $event[$target] = (string) $value;
            }
            $status = strtolower(str_replace([' ', '-'], '_', trim($source['status'])));
            $event['normalized_status'] = in_array($status, ['received', 'queued', 'in_progress', 'ready_for_pickup', 'shipped', 'delivered', 'rejected', 'on_hold', 'cancelled', 'unknown'], true) ? $status : 'unknown';
            foreach (['tracking_number', 'carrier'] as $field) {
                if (array_key_exists($field, $source)) {
                    if (! is_string($source[$field]) || $source[$field] === '' || strlen($source[$field]) > 240) { return new Result('validation_rejection', 'callback.tracking_invalid'); }
                    $event[$field] = $source[$field];
                }
            }
            $normalized[] = $event;
        }
        $handles = [];
        foreach ($normalized as $event) {
            $proposal = $services->request('commands', 'propose', ['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event' => $event]);
            $handles[] = $proposal['proposal_handle'];
        }
        return new Result('success', 'callback.proposed', ['proposal_handles' => $handles, 'applied' => false]);
    },
    'catalog.preview' => static function (Invocation $invocation, Services $services): Result {
        $records = $invocation->input()['records'] ?? null;
        if (! is_array($records) || ! array_is_list($records) || $records === [] || count($records) > 1000) { return new Result('validation_rejection', 'catalog.records_invalid'); }
        $proposals = [];
        foreach ($records as $record) {
            if (is_array($record) && is_string($record['vendor_product'] ?? null) && preg_match('/\A[1-9][0-9]{0,14}\z/D', $record['vendor_product']) === 1) { $record['vendor_product'] = (int) $record['vendor_product']; }
            if (! is_array($record) || ! is_string($record['source_key'] ?? null) || preg_match('/\A[\x21-\x7e]{1,200}\z/D', $record['source_key']) !== 1 || ! is_string($record['display_name'] ?? null) || trim($record['display_name']) === '' || strlen($record['display_name']) > 240 || (isset($record['canonical_item_handle']) && (! is_string($record['canonical_item_handle']) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $record['canonical_item_handle']) !== 1)) || (isset($record['vendor_product']) && (! is_int($record['vendor_product']) || $record['vendor_product'] < 1 || $record['vendor_product'] > 9007199254740991))) { return new Result('validation_rejection', 'catalog.row_invalid'); }
            $proposals[] = ['source_key' => $record['source_key'], 'display_name' => $record['display_name'], 'canonical_item_handle' => $record['canonical_item_handle'] ?? null, 'mapping_values' => isset($record['vendor_product']) ? ['vendor_product' => $record['vendor_product']] : null, 'warnings' => []];
        }
        return new Result('success', 'catalog.preview', ['proposals' => $proposals, 'applied' => false]);
    },
    'reference.refresh' => static fn(Invocation $invocation, Services $services): Result => new Result('success', 'reference.refreshed', $services->request('storage', 'write', ['key' => 'reference', 'expected_revision' => $invocation->input()['expected_revision'] ?? 0, 'value' => ['synthetic' => true, 'values' => ['ground', 'overnight']]])),
];
