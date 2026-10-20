<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\LocalSyntheticBroker;

$assertions=0;function check(bool $condition,string $reason):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException($reason);}}
function denied(callable $operation):void{try{$operation();}catch(Throwable){check(true,'denied');return;}check(false,'expected denial');}
$database=sys_get_temp_dir().'/kit-local-broker-'.bin2hex(random_bytes(6)).'.sqlite';$broker=new LocalSyntheticBroker($database);
$base=['protocol_version'=>'1','invocation_id'=>'invocation-a','command_id'=>'command-a','attempt_id'=>'attempt-a','correlation_id'=>'correlation-a','package_id'=>'uniscript/demo','execution_revision'=>'revision-a','organization_id'=>'organization-a','location_id'=>'location-a','connection_id'=>'connection-a','environment'=>'sandbox','deployment_environment'=>'local','actor_id'=>'actor-a','operation'=>'reference.refresh','schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'key-a','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+60),'request_budget'=>10,'input'=>[],'service_grant'=>bin2hex(random_bytes(32))];
$resources=['reference'=>['type'=>'object','properties'=>['ready'=>['type'=>'boolean']],'required'=>['ready'],'additionalProperties'=>false]];$broker->register($base,$resources);
function request(LocalSyntheticBroker $broker,array $envelope,string $action,array $args):array{return $broker->request(['invocation_id'=>$envelope['invocation_id'],'grant'=>$envelope['service_grant'],'service'=>'storage','action'=>$action,'arguments'=>$args]);}
check(request($broker,$base,'write',['key'=>'reference','expected_revision'=>0,'value'=>['ready'=>true]])['revision']===1,'typed resource write');check(request($broker,$base,'read',['key'=>'reference'])['record']['value']['ready']===true,'scoped resource read');
denied(fn()=>request($broker,$base,'write',['key'=>'reference','expected_revision'=>0,'value'=>['ready'=>true]]));denied(fn()=>request($broker,$base,'write',['key'=>'reference','expected_revision'=>1,'value'=>['ready'=>'true']]));denied(fn()=>request($broker,$base,'write',['key'=>'undeclared','expected_revision'=>0,'value'=>[]]));denied(fn()=>request($broker,$base,'read',['key'=>'reference','connection_id'=>'connection-other']));
$other=array_replace($base,['invocation_id'=>'invocation-b','connection_id'=>'connection-b','service_grant'=>bin2hex(random_bytes(32))]);$broker->register($other,$resources);check(request($broker,$other,'read',['key'=>'reference'])['record']===null,'cross connection data isolated');
$health=array_replace($base,['invocation_id'=>'invocation-c','operation'=>'health.probe','service_grant'=>bin2hex(random_bytes(32))]);$broker->register($health,$resources);denied(fn()=>request($broker,$health,'read',['key'=>'reference']));
$stolen=array_replace($base,['invocation_id'=>'different-invocation']);denied(fn()=>request($broker,$stolen,'read',['key'=>'reference']));
$budget=array_replace($base,['invocation_id'=>'invocation-d','request_budget'=>0,'service_grant'=>bin2hex(random_bytes(32))]);$broker->register($budget,$resources);denied(fn()=>request($broker,$budget,'read',['key'=>'reference']));
denied(fn()=>$broker->register(array_replace($base,['invocation_id'=>'production','environment'=>'production','service_grant'=>bin2hex(random_bytes(32))]),$resources));
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
