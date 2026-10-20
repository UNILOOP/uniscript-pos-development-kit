<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ResourceSchema;

$assertions=0;function check(bool $value,string $reason):void{global $assertions;$assertions++;if(!$value){throw new RuntimeException($reason);}}
function denied(callable $operation):void{try{$operation();}catch(Throwable){check(true,'denied');return;}check(false,'expected denial');}
function objectSchema(array $properties,array $required=[]):stdClass{return (object)['type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false];}
$row=objectSchema(['kind'=>(object)['type'=>'string','enum'=>['shipping_service','rx_status']],'code'=>(object)['type'=>'string','maxLength'=>120],'label'=>(object)['type'=>'string','maxLength'=>240]],['kind','code','label']);
$schema=objectSchema(['records'=>(object)['type'=>'array','maxItems'=>1000,'items'=>$row]],['records']);
ResourceSchema::assertDefinition($schema);ResourceSchema::validateValues($schema,['records'=>[['kind'=>'shipping_service','code'=>'1','label'=>'Standard']]]);check(true,'nested typed resource accepted');ResourceSchema::validateValues($schema,['records'=>[]]);check(true,'bounded empty list accepted');
foreach([[],['records'=>['not'=>'list']],['records'=>[['kind'=>'unknown','code'=>'1','label'=>'x']]],['records'=>[['kind'=>'rx_status','code'=>null,'label'=>'x']]],['records'=>[['kind'=>'rx_status','code'=>'1','label'=>'x','secret'=>'unexpected']]],['records'=>array_fill(0,1001,['kind'=>'rx_status','code'=>'1','label'=>'x'])]] as $value){denied(fn()=>ResourceSchema::validateValues($schema,$value));}
foreach([0,1001,null] as $max){$invalid=objectSchema(['records'=>(object)['type'=>'array','items'=>$row,'maxItems'=>$max]]);denied(fn()=>ResourceSchema::assertDefinition($invalid));}
foreach([-1,2,null] as $min){$invalid=objectSchema(['records'=>(object)['type'=>'array','items'=>$row,'maxItems'=>1,'minItems'=>$min]]);denied(fn()=>ResourceSchema::assertDefinition($invalid));}
$invalid=objectSchema(['records'=>(object)['type'=>'array','items'=>$row,'maxItems'=>1,'uniqueItems'=>true]]);denied(fn()=>ResourceSchema::assertDefinition($invalid));
$invalid=objectSchema(['records'=>(object)['type'=>'array','items'=>[],'maxItems'=>1]]);denied(fn()=>ResourceSchema::assertDefinition($invalid));
$invalid=objectSchema(['value'=>(object)['type'=>'string','default'=>'x']]);denied(fn()=>ResourceSchema::assertDefinition($invalid));
$invalid=objectSchema([]);$invalid->properties=[];denied(fn()=>ResourceSchema::assertDefinition($invalid));
$deep=(object)['type'=>'string'];for($i=0;$i<7;$i++){$deep=objectSchema(['nested'=>$deep]);}denied(fn()=>ResourceSchema::assertDefinition($deep));
$many=[];for($i=0;$i<40;$i++){$many['field'.$i]=objectSchema(['a'=>(object)['type'=>'string'],'b'=>(object)['type'=>'string'],'c'=>(object)['type'=>'string'],'d'=>(object)['type'=>'string']]);}denied(fn()=>ResourceSchema::assertDefinition(objectSchema($many)));
denied(fn()=>ResourceSchema::assertDefinition((object)['type'=>'array','items'=>$row,'maxItems'=>1]));
// Flat configuration and credentials remain closed to nested objects.
denied(fn()=>UniScript\Integration\ConfigurationSchema::assertDefinition($schema));
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
