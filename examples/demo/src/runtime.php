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
    'orders.validate' => static fn(Invocation $invocation, Services $services): Result => isset($invocation->input()['order_id']) ? new Result('success', 'order.valid') : new Result('validation_rejection', 'order.invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]),
    'orders.submit' => static function (Invocation $invocation, Services $services): Result {
        if (!isset($invocation->input()['order_id'])) { return new Result('validation_rejection', 'order.invalid', [], [['path' => 'order_id', 'reason_code' => 'required']]); }
        $response = $services->request('http', 'request', ['profile' => 'synthetic.orders', 'operation_key' => $invocation->operation(), 'parameters' => [], 'idempotency_key' => $invocation->idempotencyKey(), 'body' => $invocation->input()]);
        if ($response['status'] === 'unknown') { return new Result('unknown_remote_outcome', 'order.acceptance_unknown', [], [], ['action' => 'reconcile', 'resubmit_allowed' => false]); }
        return new Result('remote_acceptance', 'order.accepted', ['external_reference_handle' => $response['external_reference_handle']]);
    },
    'orders.reconcile' => static function (Invocation $invocation, Services $services): Result {
        $response = $services->request('http', 'request', ['profile' => 'synthetic.reconcile', 'operation_key' => $invocation->operation(), 'parameters' => [], 'idempotency_key' => $invocation->idempotencyKey()]);
        return $response['status'] === 'accepted' ? new Result('remote_acceptance', 'order.reconciled', ['external_reference_handle' => $response['external_reference_handle']]) : new Result('unknown_remote_outcome', 'order.not_observed', [], [], ['action' => 'operator_review', 'resubmit_allowed' => false]);
    },
    'inbound.decode' => static function (Invocation $invocation, Services $services): Result {
        $input = $invocation->input();
        if (($input['authenticated_receipt'] ?? false) !== true || !is_string($input['receipt_handle'] ?? null)) { return new Result('validation_rejection', 'callback.unauthenticated'); }
        return new Result('success', 'callback.proposed', $services->request('commands', 'propose', ['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event_id' => $input['event_id'] ?? null, 'status' => 'received']));
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
