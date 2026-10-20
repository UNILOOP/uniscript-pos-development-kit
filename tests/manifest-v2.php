<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConfigurationSchema;
use UniScript\Integration\Manifest;

$assertions=0;function check(bool $value,string $message):void{global $assertions;$assertions++;if(!$value){throw new RuntimeException($message);}}
function denied(callable $operation):void{try{$operation();}catch(Throwable){check(true,'invalid profile rejected');return;}check(false,'invalid profile accepted');}
$root=sys_get_temp_dir().'/kit-manifest-v2-'.bin2hex(random_bytes(6));mkdir($root.'/src',0700,true);mkdir($root.'/schemas',0700);
foreach(['integration.json','composer.json','composer.lock'] as $file){copy(dirname(__DIR__).'/examples/starter/'.$file,$root.'/'.$file);}
copy(dirname(__DIR__).'/examples/starter/src/runtime.php',$root.'/src/runtime.php');foreach(['input.json','output.json'] as $file){copy(dirname(__DIR__).'/examples/starter/schemas/'.$file,$root.'/schemas/'.$file);}
$original=json_decode(file_get_contents($root.'/integration.json'),true,32,JSON_THROW_ON_ERROR);$manifest=$original;
$manifest['manifest_version']=2;$manifest['configuration_schema']='schemas/configuration.json';$manifest['credential_schema']='schemas/credential.json';$manifest['resource_schemas']=(object)['state'=>'schemas/state.json'];
$definition=(object)['type'=>'object','properties'=>(object)['label'=>(object)['type'=>'string','minLength'=>1,'maxLength'=>120],'enabled'=>(object)['type'=>'boolean'],'amount'=>(object)['type'=>'number','minimum'=>0,'maximum'=>10,'enum'=>[1,2.5]]],'required'=>['label'],'additionalProperties'=>false];
$empty=(object)['type'=>'object','properties'=>(object)[],'required'=>[],'additionalProperties'=>false];
function writeManifest(array $value):void{global $root;file_put_contents($root.'/integration.json',json_encode($value,JSON_THROW_ON_ERROR));}
writeManifest($manifest);file_put_contents($root.'/schemas/configuration.json',json_encode($definition,JSON_THROW_ON_ERROR));file_put_contents($root.'/schemas/credential.json',json_encode($empty,JSON_THROW_ON_ERROR));file_put_contents($root.'/schemas/state.json',json_encode($empty,JSON_THROW_ON_ERROR));
check(Manifest::read($root)['manifest_version']===2,'manifest v2 accepted');ConfigurationSchema::validateValues($definition,['label'=>'Synthetic','enabled'=>true,'amount'=>2.5]);check(true,'typed scalar values');
foreach([['label'=>'Synthetic','amount'=>1.0],['label'=>'Synthetic','extra'=>true],['label'=>''],['enabled'=>true],['label'=>null]] as $values){denied(fn()=>ConfigurationSchema::validateValues($definition,$values));}
foreach(['configuration_schema','credential_schema'] as $required){$invalid=$manifest;unset($invalid[$required]);writeManifest($invalid);denied(fn()=>Manifest::read($root));}
$invalid=$manifest;$invalid['manifest_version']=1;writeManifest($invalid);denied(fn()=>Manifest::read($root));
$invalid=$manifest;$invalid['resource_schemas']=[];writeManifest($invalid);denied(fn()=>Manifest::read($root));
foreach(['../outside.json','schemas/missing.json','schemas/input.php','https://evil.example/schema.json'] as $path){$invalid=$manifest;$invalid['configuration_schema']=$path;writeManifest($invalid);denied(fn()=>Manifest::read($root));}
writeManifest($manifest);
foreach(['default'=>'x','$ref'=>'#','pattern'=>'x','nullable'=>true] as $key=>$value){$invalid=clone $definition;$invalid->{$key}=$value;file_put_contents($root.'/schemas/configuration.json',json_encode($invalid,JSON_THROW_ON_ERROR));denied(fn()=>Manifest::read($root));}
file_put_contents($root.'/schemas/configuration.json',json_encode($definition,JSON_THROW_ON_ERROR));
$invalid=clone $empty;$invalid->properties=[];denied(fn()=>ConfigurationSchema::assertDefinition($invalid));
$credentials=clone $empty;$credentials->properties=(object)['secret'=>(object)['type'=>'boolean']];denied(fn()=>ConfigurationSchema::assertDefinition($credentials,true));
foreach([(object)['type'=>'string','minLength'=>5,'maxLength'=>1],(object)['type'=>'number','minimum'=>10,'maximum'=>1],(object)['type'=>'string','enum'=>[false]],(object)['type'=>'string','title'=>'<unsafe>']] as $field){$invalid=clone $empty;$invalid->properties=(object)['value'=>$field];denied(fn()=>ConfigurationSchema::assertDefinition($invalid));}
writeManifest($original);check(Manifest::read($root)['manifest_version']===1,'manifest v1 preserved');
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
