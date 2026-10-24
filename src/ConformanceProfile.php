<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Trusted synthetic scenarios, selected by platform profile rather than package assertions. */
final class ConformanceProfile
{
    public static function scenarios(string $operation,string $packageId='',int $manifestVersion=1,string $packageVersion=''): array
    {
        if ($packageId === 'uniscript/reference-pos') {
            $fixtures = JsonDocument::decode(file_get_contents(dirname(__DIR__).'/fixtures/reference-conformance.json'), true);
            return $fixtures[$operation] ?? throw new RuntimeException('trusted_scenario_not_available:'.$operation);
        }
        if ($packageId === 'uniscript/lifefile-v2') {
            $fixtures = JsonDocument::decode(file_get_contents(dirname(__DIR__).'/tests/fixtures/lifefile-api-v2-conformance.json'), true);
            return $fixtures[$operation] ?? throw new RuntimeException('trusted_scenario_not_available:'.$operation);
        }
        if($packageId==='uniscript/lifefile'){
            $fixtures=JsonDocument::decode(file_get_contents(dirname(__DIR__).'/tests/fixtures/'.($packageVersion!==''&&version_compare($packageVersion,'1.0.5','>=')?'lifefile-amendment-conformance.json':($packageVersion!==''&&version_compare($packageVersion,'1.0.4','>=')?'lifefile-canonical-conformance.json':'lifefile-conformance.json'))),true);
            return $fixtures[$operation]??throw new RuntimeException('trusted_scenario_not_available:'.$operation);
        }
        if ($packageId === 'uniscript/demo' && $packageVersion !== '' && version_compare($packageVersion, '1.0.3', '>=')) {
            if ($operation === 'orders.submit') {
                return [['input' => ['order_id' => 'synthetic-order', 'config' => ['private' => 'DO-NOT-SEND'], 'order' => ['patient' => ['name' => 'SYNTHETIC-CANARY']]], 'kind' => 'remote_acceptance', 'expected_calls' => ['http.request'], 'expected_http_body' => ['order_id' => 'synthetic-order']], ['input' => [], 'kind' => 'validation_rejection', 'expected_calls' => []]];
            }
            if ($operation === 'inbound.decode') {
                $scenarios = [['input' => ['authenticated_receipt' => true, 'receipt_handle' => 'synthetic-receipt', 'events' => [['orderId' => 'synthetic-order', 'eventId' => 'synthetic-event', 'status' => 'In Progress']]], 'kind' => 'success', 'expected_calls' => ['commands.propose'], 'expected_event' => ['external_order_reference' => 'synthetic-order', 'event_id' => 'synthetic-event', 'normalized_status' => 'in_progress']], ['input' => ['authenticated_receipt' => true, 'receipt_handle' => 'synthetic-receipt', 'events' => [['orderId' => 'synthetic-order', 'eventId' => 'synthetic-event', 'status' => 'received'], ['orderId' => null, 'eventId' => 'malformed-event', 'status' => 'delivered']]], 'kind' => 'validation_rejection', 'expected_calls' => []]];
                $events = [];
                $expected = [];
                for ($index = 0; $index < 30; $index++) {
                    $events[] = ['orderId' => 'synthetic-order-'.$index, 'eventId' => 'synthetic-event-'.$index, 'status' => 'received'];
                    $expected[] = ['external_order_reference' => 'synthetic-order-'.$index, 'event_id' => 'synthetic-event-'.$index, 'normalized_status' => 'received'];
                }
                $scenarios[] = ['input' => ['authenticated_receipt' => true, 'receipt_handle' => 'synthetic-receipt', 'events' => $events], 'kind' => 'success', 'expected_calls' => array_fill(0, 30, 'commands.propose'), 'expected_events' => $expected];
                return $scenarios;
            }
        }
        if ($packageId === 'uniscript/demo' && $operation === 'catalog.preview' && $packageVersion !== '' && version_compare($packageVersion, '1.0.2', '>=')) {
            return [['input' => ['records' => [['source_key' => 'synthetic-product-a', 'display_name' => 'Synthetic saline', 'vendor_product' => 7]]], 'kind' => 'success', 'expected_calls' => [], 'expected_proposals' => [['source_key' => 'synthetic-product-a', 'display_name' => 'Synthetic saline', 'canonical_item_handle' => null, 'mapping_values' => ['vendor_product' => 7], 'warnings' => []]]], ['input' => ['records' => [['source_key' => '', 'display_name' => 'Invalid']]], 'kind' => 'validation_rejection', 'expected_calls' => []]];
        }
        if($packageId==='uniscript/demo'&&$manifestVersion===2&&in_array($operation,['configuration.validate','health.probe'],true)){return [['input'=>['config'=>['vendor_id'=>'10001','location_id'=>'10002','api_network_id'=>'10003','mode'=>'mock']],'kind'=>'success'],['input'=>['config'=>['vendor_id'=>false,'location_id'=>'10002','api_network_id'=>'10003']],'kind'=>'validation_rejection']];}
        $order=['order_id'=>'synthetic-order'];
        $receipt=['authenticated_receipt'=>true,'receipt_handle'=>'synthetic-receipt','event_id'=>'synthetic-event'];
        return match($operation){
            'identity.describe'=>[['input'=>[],'kind'=>'success']],
            'health.probe'=>[['input'=>[],'kind'=>'success']],
            'configuration.validate'=>[['input'=>['mode'=>'mock'],'kind'=>'success'],['input'=>['mode'=>false],'kind'=>'validation_rejection']],
            'orders.validate'=>[['input'=>$order,'kind'=>'success'],['input'=>[],'kind'=>'validation_rejection']],
            'orders.submit'=>[['input'=>$order,'kind'=>'remote_acceptance'],['input'=>[],'kind'=>'validation_rejection']],
            'orders.reconcile'=>[['input'=>$order,'kind'=>'remote_acceptance']],
            'inbound.decode'=>[['input'=>$receipt,'kind'=>'success'],['input'=>['authenticated_receipt'=>false],'kind'=>'validation_rejection']],
            'catalog.preview'=>[['input'=>[],'kind'=>'success']],
            'reference.refresh'=>[['input'=>['expected_revision'=>0],'kind'=>'success']],
            default=>throw new RuntimeException('trusted_scenario_not_available:'.$operation),
        };
    }

    public static function readinessOperation(string $packageId): string
    {
        return in_array($packageId, ['uniscript/lifefile-v2', 'uniscript/reference-pos'], true) ? 'identity.describe' : 'health.probe';
    }

    public static function serviceResponse(string $packageId,array $scenario,array $request,SyntheticHost $host):array
    {
        $host->authorize($request);
        if (isset($scenario['expected_event']) && $request['service'] === 'commands' && $request['action'] === 'propose' && ($request['arguments'] ?? null) !== ['command' => 'normalized_event', 'receipt_handle' => $scenario['input']['receipt_handle'], 'event' => $scenario['expected_event']]) { throw new RuntimeException('conformance_callback_projection'); }
        if ($request['service'] === 'http' && $request['action'] === 'request') {
            if (isset($scenario['expected_http_body']) && ($request['arguments']['body'] ?? null) !== $scenario['expected_http_body']) { throw new RuntimeException('conformance_http_body'); }
            foreach ($scenario['expected_http_projection'] ?? [] as $path => $expectedValue) {
                $actual = $request['arguments'];
                foreach (explode('.', $path) as $part) {
                    if (! is_array($actual) || ! array_key_exists($part, $actual)) { throw new RuntimeException('conformance_http_projection_missing:'.$path); }
                    $actual = $actual[$part];
                }
                if ($actual !== $expectedValue) { throw new RuntimeException('conformance_http_projection_mismatch:'.$path); }
            }
        }
        $key=$request['service'].'.'.$request['action'];
        if(isset($scenario['services'][$key])){return $scenario['services'][$key];}
        if ($packageId === 'uniscript/lifefile-v2' && $key === 'http.request') {
            $args = $request['arguments'];
            if (($args['profile'] ?? null) !== 'lifefile-v2.api' || !is_string($args['operation_key'] ?? null) || !is_string($args['parameters']['operation_id'] ?? null) || array_intersect(array_keys($args), ['url', 'method', 'path', 'path_template', 'headers']) !== []) { throw new RuntimeException('synthetic_lifefile_v2_endpoint_denied'); }
            return ['status' => 'accepted', 'external_reference_handle' => 'synthetic-reference', 'data' => ['type' => 'success', 'data' => []]];
        }
        if($packageId==='uniscript/lifefile'&&$key==='http.request'){
            $args=$request['arguments'];
            $expected=['lifefile.orders'=>'orders.submit','lifefile.status'=>'orders.status.update','lifefile.shipping'=>'orders.shipping.update'];
            if(!isset($expected[$args['profile']??''])||($args['operation_key']??null)!==$expected[$args['profile']]||array_intersect(array_keys($args),['url','method','path','path_template','headers'])!==[]){throw new RuntimeException('synthetic_lifefile_endpoint_denied');}
            return ['status'=>'accepted','external_reference_handle'=>'synthetic-reference'];
        }
        return $host->transport($request);
    }

    public static function verify(string $operation,array $scenario,Result $result,string $packageId,array $calls=[],array $requests=[]):void
    {
        if($result->kind!==$scenario['kind']){throw new RuntimeException('conformance_unexpected_result:'.$operation);}
        if (isset($scenario['expected_calls']) && $calls !== $scenario['expected_calls']) { throw new RuntimeException('conformance_service_call_sequence:'.$operation); }
        if (isset($scenario['expected_events'])) {
            $actualEvents = [];
            foreach ($requests as $request) {
                if ($request['service'] === 'commands' && $request['action'] === 'propose') {
                    if (($request['arguments']['command'] ?? null) !== 'normalized_event' || ($request['arguments']['receipt_handle'] ?? null) !== $scenario['input']['receipt_handle']) { throw new RuntimeException('conformance_callback_scope'); }
                    $actualEvents[] = $request['arguments']['event'] ?? null;
                }
            }
            if ($actualEvents !== $scenario['expected_events']) { throw new RuntimeException('conformance_callback_batch_projection'); }
        }
        if($result->kind==='validation_rejection'){return;}
        if (isset($scenario['expected_proposals']) && ($result->data['proposals'] ?? null) !== $scenario['expected_proposals']) { throw new RuntimeException('conformance_catalog_projection'); }
        if($operation==='identity.describe'&&($result->data['package_id']??null)!==$packageId){throw new RuntimeException('conformance_identity');}
        if($result->kind==='remote_acceptance'&&in_array($operation,['orders.submit','orders.reconcile','orders.status.update','orders.shipping.update'],true)&&(!is_string($result->data['external_reference_handle']??null)||$result->data['external_reference_handle']==='')){throw new RuntimeException('conformance_acceptance_reference');}
        if(in_array($operation,['catalog.preview','inbound.decode'],true)&&($result->data['applied']??null)!==false){throw new RuntimeException('conformance_no_core_mutation');}
        if($operation==='reference.refresh'&&$packageId!=='uniscript/lifefile-v2'&&($result->data['revision']??null)!==1){throw new RuntimeException('conformance_resource_revision');}
    }
}
