<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Trusted synthetic scenarios, selected by platform profile rather than package assertions. */
final class ConformanceProfile
{
    public static function scenarios(string $operation): array
    {
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

    public static function verify(string $operation,array $scenario,Result $result,string $packageId):void
    {
        if($result->kind!==$scenario['kind']){throw new RuntimeException('conformance_unexpected_result:'.$operation);}
        if($result->kind==='validation_rejection'){return;}
        if($operation==='identity.describe'&&($result->data['package_id']??null)!==$packageId){throw new RuntimeException('conformance_identity');}
        if(in_array($operation,['orders.submit','orders.reconcile'],true)&&(!is_string($result->data['external_reference_handle']??null)||$result->data['external_reference_handle']==='')){throw new RuntimeException('conformance_acceptance_reference');}
        if(in_array($operation,['catalog.preview','inbound.decode'],true)&&($result->data['applied']??null)!==false){throw new RuntimeException('conformance_no_core_mutation');}
        if($operation==='reference.refresh'&&($result->data['revision']??null)!==1){throw new RuntimeException('conformance_resource_revision');}
    }
}
