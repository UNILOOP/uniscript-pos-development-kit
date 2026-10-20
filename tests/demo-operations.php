<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use UniScript\Integration\Invocation;
use UniScript\Integration\Services;

$handlers=require dirname(__DIR__).'/examples/demo/src/runtime.php';
$services=new class implements Services {
    public array $calls=[];
    public function request(string $service,string $action,array $arguments):array {
        $this->calls[]=compact('service','action','arguments');
        return match($service.'.'.$action){'http.request'=>['status'=>'accepted','external_reference_handle'=>'synthetic-reference'],'commands.propose'=>['proposal_handle'=>'synthetic-proposal','applied'=>false],default=>throw new RuntimeException('unexpected_service')};
    }
};
$invoke=static function(string $operation,array $input,int $budget=100)use($handlers,$services){$services->calls=[];$env=['protocol_version'=>'1','invocation_id'=>'synthetic-operation','command_id'=>'command','attempt_id'=>'attempt','correlation_id'=>'correlation','package_id'=>'uniscript/demo','execution_revision'=>'execution','connection_id'=>'connection','organization_id'=>'organization','location_id'=>'location','environment'=>'local','actor_id'=>'actor','operation'=>$operation,'schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'synthetic-key','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+30),'request_budget'=>$budget,'input'=>$input,'service_grant'=>'synthetic-grant'];return $handlers[$operation](Invocation::fromArray($env),$services);};
$assertions=0;
$check=static function(bool $condition,string $reason)use(&$assertions):void{if(!$condition){throw new RuntimeException($reason);}$assertions++;};
$result=$invoke('orders.submit',['order_id'=>'synthetic-order','scenario'=>'accepted_then_lost','config'=>['DO-NOT-SEND'=>'private'],'order'=>['patient'=>['name'=>'SYNTHETIC-CANARY']]]);
$check($result->kind==='remote_acceptance'&&$services->calls[0]['arguments']['body']===['order_id'=>'synthetic-order','scenario'=>'accepted_then_lost'],'canonical_order_body_not_projected');
$result=$invoke('orders.submit',['order_id'=>['invalid']]);$check($result->kind==='validation_rejection'&&$services->calls===[],'malformed_order_id_called_transport');
$receipt=['authenticated_receipt'=>true,'receipt_handle'=>'synthetic-receipt'];
$statuses=['received','queued','in_progress','ready_for_pickup','shipped','delivered','rejected','on_hold','cancelled','unknown'];
$events=[];foreach($statuses as $index=>$status){$events[]=['orderId'=>'order-'.$index,'eventId'=>'event-'.$index,'status'=>$status];}
$result=$invoke('inbound.decode',[...$receipt,'events'=>$events]);
$check($result->kind==='success'&&$result->data['applied']===false&&count($services->calls)===10,'bounded_event_batch_failed');
foreach($services->calls as $index=>$call){$check($call===['service'=>'commands','action'=>'propose','arguments'=>['command'=>'normalized_event','receipt_handle'=>'synthetic-receipt','event'=>['external_order_reference'=>'order-'.$index,'event_id'=>'event-'.$index,'normalized_status'=>$statuses[$index]]]],'callback_projection_changed');}
$result=$invoke('inbound.decode',[...$receipt,'events'=>[['orderId'=>123,'eventId'=>456,'status'=>'In Progress','tracking_number'=>'SYNTHETIC-TRACK','carrier'=>'Other','patient_name'=>'MUST-NOT-EXPORT']]]);
$check($result->kind==='success'&&$services->calls[0]['arguments']['event']===['external_order_reference'=>'123','event_id'=>'456','normalized_status'=>'in_progress','tracking_number'=>'SYNTHETIC-TRACK','carrier'=>'Other'],'callback_identity_or_field_projection');
foreach([[],['authenticated_receipt'=>false,'receipt_handle'=>'synthetic-receipt','events'=>$events],[...$receipt,'events'=>[]],[...$receipt,'events'=>array_fill(0,101,$events[0])],[...$receipt,'events'=>[$events[0],['orderId'=>null,'eventId'=>'bad','status'=>'delivered']]],[...$receipt,'events'=>[['orderId'=>'order','eventId'=>'event','status'=>[]]]],[...$receipt,'events'=>[['orderId'=>'order','eventId'=>'event','status'=>'received','tracking_number'=>[]]]]] as $invalid){$result=$invoke('inbound.decode',$invalid);$check($result->kind==='validation_rejection'&&$services->calls===[],'malformed_callback_caused_partial_proposal');}
$result=$invoke('inbound.decode',[...$receipt,'events'=>$events],2);$check($result->kind==='validation_rejection'&&$services->calls===[],'insufficient_budget_caused_partial_proposal');
$result=$invoke('inbound.decode',[...$receipt,'events'=>[['orderId'=>'order','eventId'=>'event','status'=>'new-vendor-unknown-state']]]);$check($services->calls[0]['arguments']['event']['normalized_status']==='unknown','unknown_status_invented_progress');
$scenarios=\UniScript\Integration\ConformanceProfile::scenarios('inbound.decode','uniscript/demo',2,'1.0.3');
$batch=$scenarios[array_key_last($scenarios)];
$result=$invoke('inbound.decode',$batch['input']);
$requests=$services->calls;
$calls=array_map(static fn(array $request):string=>$request['service'].'.'.$request['action'],$requests);
\UniScript\Integration\ConformanceProfile::verify('inbound.decode',$batch,$result,'uniscript/demo',$calls,$requests);
$check(count($requests)===30,'trusted_batch_not_30_events');
$requests[1]['arguments']['event']=$requests[0]['arguments']['event'];
$rejected=false;
try { \UniScript\Integration\ConformanceProfile::verify('inbound.decode',$batch,$result,'uniscript/demo',$calls,$requests); } catch (RuntimeException) { $rejected=true; }
$check($rejected,'trusted_batch_accepted_repeated_event_in_place_of_next');
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
