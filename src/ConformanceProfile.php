<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Trusted synthetic scenarios, selected by platform profile rather than package assertions. */
final class ConformanceProfile
{
    public static function scenarios(string $operation,string $packageId='',int $manifestVersion=1,string $packageVersion=''): array
    {
        if($packageId==='uniscript/lifefile'){
            $fixtures=JsonDocument::decode(file_get_contents(dirname(__DIR__).'/tests/fixtures/'.($packageVersion!==''&&version_compare($packageVersion,'1.0.4','>=')?'lifefile-canonical-conformance.json':'lifefile-conformance.json')),true);
            return $fixtures[$operation]??throw new RuntimeException('trusted_scenario_not_available:'.$operation);
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

    public static function serviceResponse(string $packageId,array $scenario,array $request,SyntheticHost $host):array
    {
        $host->authorize($request);
        if ($request['service'] === 'http' && $request['action'] === 'request') {
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
        if($packageId==='uniscript/lifefile'&&$key==='http.request'){
            $args=$request['arguments'];
            $expected=['lifefile.orders'=>'orders.submit','lifefile.status'=>'orders.status.update','lifefile.shipping'=>'orders.shipping.update'];
            if(!isset($expected[$args['profile']??''])||($args['operation_key']??null)!==$expected[$args['profile']]||array_intersect(array_keys($args),['url','method','path','path_template','headers'])!==[]){throw new RuntimeException('synthetic_lifefile_endpoint_denied');}
            return ['status'=>'accepted','external_reference_handle'=>'synthetic-reference'];
        }
        return $host->transport($request);
    }

    public static function verify(string $operation,array $scenario,Result $result,string $packageId,array $calls=[]):void
    {
        if($result->kind!==$scenario['kind']){throw new RuntimeException('conformance_unexpected_result:'.$operation);}
        if (isset($scenario['expected_calls']) && $calls !== $scenario['expected_calls']) { throw new RuntimeException('conformance_service_call_sequence:'.$operation); }
        if($result->kind==='validation_rejection'){return;}
        if($operation==='identity.describe'&&($result->data['package_id']??null)!==$packageId){throw new RuntimeException('conformance_identity');}
        if(in_array($operation,['orders.submit','orders.reconcile','orders.status.update','orders.shipping.update'],true)&&(!is_string($result->data['external_reference_handle']??null)||$result->data['external_reference_handle']==='')){throw new RuntimeException('conformance_acceptance_reference');}
        if(in_array($operation,['catalog.preview','inbound.decode'],true)&&($result->data['applied']??null)!==false){throw new RuntimeException('conformance_no_core_mutation');}
        if($operation==='reference.refresh'&&($result->data['revision']??null)!==1){throw new RuntimeException('conformance_resource_revision');}
    }
}
