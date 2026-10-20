<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Invocation;
use UniScript\Integration\Result;
use UniScript\Integration\SchemaValidator;

$assertions=0;
function check(bool $condition,string $label):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException('failed:'.$label);}}
function denied(callable $operation,string $label):void{try{$operation();}catch(Throwable){check(true,$label);return;}check(false,$label);}
$root=sys_get_temp_dir().'/kit-schema-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/schemas',0700);
function schema(array|bool $schema,string $name='test.json'):string{global $root;file_put_contents($root.'/schemas/'.$name,json_encode($schema,JSON_THROW_ON_ERROR));return 'schemas/'.$name;}
function validates(mixed $value,array|bool $definition):void{global $root;(new SchemaValidator($root))->validate($value,schema($definition));check(true,'valid schema value');}
function rejects(mixed $value,array|bool $definition):void{global $root;denied(fn()=>(new SchemaValidator($root))->validate($value,schema($definition)),'invalid schema value');}
validates(['id'=>'opaque','items'=>[1,2]],['type'=>'object','required'=>['id','items'],'properties'=>['id'=>['type'=>'string'],'items'=>['type'=>'array','items'=>['type'=>'integer'],'minItems'=>1]],'additionalProperties'=>false]);
rejects([],['type'=>'object','required'=>['id']]);rejects(['id'=>null],['type'=>'object','properties'=>['id'=>['type'=>'string']]]);rejects(['extra'=>1],['type'=>'object','additionalProperties'=>false]);
rejects([1,'two'],['type'=>'array','items'=>['type'=>'integer']]);rejects([],['type'=>'array','minItems'=>1]);rejects([1,2],['type'=>'array','maxItems'=>1]);
validates(1.0,['type'=>'integer']);rejects(1.5,['type'=>'integer']);rejects('x',['enum'=>['x','x']]);validates(null,['type'=>['string','null']]);validates('yes',['enum'=>['yes','no']]);rejects('no',['const'=>'yes']);rejects('other',['enum'=>['yes','no']]);
validates('é',['type'=>'string','minLength'=>1,'maxLength'=>1]);rejects('éé',['maxLength'=>1]);rejects(3,['minimum'=>4]);rejects(5,['maximum'=>4]);
validates(['id'=>'opaque'],['$defs'=>['id'=>['type'=>'string']],'properties'=>['id'=>['$ref'=>'#/$defs/id']]]);
schema(['type'=>'string'],'shared.json');validates(['id'=>'opaque'],['properties'=>['id'=>['$ref'=>'shared.json']]]);
rejects(['id'=>1],['properties'=>['id'=>['$ref'=>'shared.json']]]);
rejects([],['$ref'=>'https://evil.example/schema.json']);rejects([],['$ref'=>'../../secret.json']);rejects([],['$ref'=>'#/$defs/missing']);rejects([],['$ref'=>'#']);
foreach(['oneOf'=>[],'format'=>'date','pattern'=>'unsafe','unevaluatedProperties'=>false] as $key=>$value){rejects([],[$key=>$value]);}
rejects([],['$defs'=>['unused'=>['not'=>[]]]]);rejects([],['properties'=>[]]);rejects([],['required'=>[1]]);rejects([],['type'=>'unknown']);rejects([],['items'=>[]]);
$env=['protocol_version'=>'1','request_budget'=>2,'input'=>[],'service_grant'=>'opaque'];
foreach(['invocation_id','command_id','attempt_id','correlation_id','package_id','execution_revision','connection_id','organization_id','location_id','operation','schema_version','routing_revision','configuration_revision','mapping_revision','credential_revision','idempotency_key','actor_id'] as $key){$env[$key]=$key==='package_id'?'uniscript/demo':($key==='operation'?'health.probe':$key);}
$env['environment']='sandbox';$env['deadline']=gmdate('Y-m-d\TH:i:s\Z',time()+60);
check(Invocation::fromArray($env)->input()===[],'valid invocation');
foreach([['extra'=>true],['input'=>[1]],['input'=>['bad'=>INF]],['request_budget'=>'2'],['deadline'=>'2099-02-30T00:00:00Z'],['deadline'=>'2099-01-01T24:00:00Z'],['deadline'=>'2099-01-01T00:00:60Z'],['deadline'=>'2099-01-01T00:00:00+00:00'],['deployment_environment'=>'production'],['deployment_environment'=>null],['actor_id'=>"bad\nactor"],['service_grant'=>str_repeat('x',8193)]] as $patch){denied(fn()=>Invocation::fromArray(array_replace($env,$patch)),'invalid invocation rejected');}
$valid=(new Result('success','operation.ready'))->jsonSerialize();check(Result::fromArray($valid)->kind==='success','valid result');
foreach([['unexpected'=>1],['data'=>[1]],['field_errors'=>['bad'=>'shape']],['recovery'=>['resubmit_allowed'=>'yes']],['recovery'=>['resubmit_allowed'=>null]],['recovery'=>['secret'=>'x']],['data'=>['resource'=>new stdClass()]]] as $patch){denied(fn()=>Result::fromArray(array_replace($valid,$patch)),'invalid result rejected');}
$missing=$valid;unset($missing['recovery']);denied(fn()=>Result::fromArray($missing),'missing result field');
denied(fn()=>new Result('unknown_remote_outcome','order.unknown',[],[],['resubmit_allowed'=>true]),'unknown cannot resubmit');
// Runtime actually applies schemas and isolates package entrypoint variable scope.
$package=$root.'/package';mkdir($package.'/src',0700,true);mkdir($package.'/schemas',0700);
foreach(['integration.json','composer.json','composer.lock'] as $file){copy(dirname(__DIR__).'/examples/starter/'.$file,$package.'/'.$file);}
foreach(['input.json','output.json'] as $file){copy(dirname(__DIR__).'/examples/starter/schemas/'.$file,$package.'/schemas/'.$file);}
$source=file_get_contents(dirname(__DIR__).'/examples/starter/src/runtime.php');
file_put_contents($package.'/src/runtime.php',str_replace('return [',"\$operation='entrypoint-variable-overwrite';\nreturn [",$source));
$runtime=new UniScript\Integration\Runtime($package,'test-revision');
$runtimeEnv=array_replace($env,['package_id'=>'uniscript/starter','execution_revision'=>'test-revision','operation'=>'health.probe']);
$services=new class implements UniScript\Integration\Services{public function request(string $service,string $action,array $arguments):array{return [];}};
check($runtime->invoke(Invocation::fromArray($runtimeEnv),$services)->kind==='success','entrypoint_scope_isolated');
file_put_contents($package.'/schemas/input.json',json_encode(['type'=>'object','required'=>['required_input']],JSON_THROW_ON_ERROR));
check($runtime->invoke(Invocation::fromArray($runtimeEnv),$services)->kind==='validation_rejection','runtime_input_schema_enforced');
file_put_contents($package.'/schemas/input.json','{"type":"object","unsupported":true}');
check($runtime->invoke(Invocation::fromArray($runtimeEnv),$services)->kind==='permanent_failure','runtime_unsupported_schema_rejected');
file_put_contents($package.'/schemas/input.json','{"type":"object"}');
file_put_contents($package.'/schemas/output.json','{"type":"object","required":["missing_output"]}');
check($runtime->invoke(Invocation::fromArray($runtimeEnv),$services)->kind==='permanent_failure','runtime_output_schema_enforced');
file_put_contents($root.'/schemas/duplicate.json','{"type":"string","type":"number"}');
denied(fn()=>(new SchemaValidator($root))->validate(1,'schemas/duplicate.json'),'duplicate_schema_keywords_rejected');
denied(fn()=>new Result('unknown_remote_outcome','order.unknown'),'unknown_requires_recovery_guidance');
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
