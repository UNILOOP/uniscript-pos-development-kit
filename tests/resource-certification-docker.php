<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$root=dirname(__DIR__);$work=sys_get_temp_dir().'/kit-resource-cert-'.bin2hex(random_bytes(6));mkdir($work,0700);$assertions=0;
foreach(['undeclared','wrong_value_type','valid'] as $case){
    $package=$work.'/'.$case;mkdir($package.'/src',0700,true);mkdir($package.'/schemas',0700);
    foreach(['integration.json','composer.json','composer.lock'] as $file){copy($root.'/examples/demo/'.$file,$package.'/'.$file);}copy($root.'/examples/demo/src/runtime.php',$package.'/src/runtime.php');foreach(['input.json','output.json','configuration.json','credential.json'] as $file){copy($root.'/examples/demo/schemas/'.$file,$package.'/schemas/'.$file);}
    $manifest=json_decode(file_get_contents($package.'/integration.json'),true,32,JSON_THROW_ON_ERROR);$manifest['manifest_version']=2;$manifest['configuration_schema']='schemas/configuration.json';$manifest['credential_schema']='schemas/credential.json';$manifest['resource_schemas']=(object)($case==='undeclared'?[]:['reference'=>'schemas/reference.json']);
    file_put_contents($package.'/integration.json',json_encode($manifest,JSON_THROW_ON_ERROR));$empty=['type'=>'object','properties'=>(object)[],'required'=>[],'additionalProperties'=>false];
    $resource=['type'=>'object','additionalProperties'=>false,'required'=>['synthetic','values'],'properties'=>['synthetic'=>['type'=>$case==='wrong_value_type'?'string':'boolean'],'values'=>['type'=>'array','maxItems'=>2,'items'=>['type'=>'string','maxLength'=>50]]]];file_put_contents($package.'/schemas/reference.json',json_encode($resource,JSON_THROW_ON_ERROR));
    $archive=$work.'/'.$case.'.zip';Process::run([PHP_BINARY,$root.'/bin/package.php',$package,$archive]);$platform=new Platform($work.'/state-'.$case);$execution='resource-'.bin2hex(random_bytes(12));$build=$platform->enqueue('builds',['execution_uuid'=>$execution,'source_sha256'=>hash_file('sha256',$archive),'package_id'=>'uniscript/demo','package_version'=>$manifest['version']],$archive);$platform->work();$build=$platform->read('builds',$build['id']);if($build['status']!=='succeeded'){throw new RuntimeException('resource_fixture_build_failed');}
    $cert=$platform->enqueue('certifications',['execution_uuid'=>$execution,'source_sha256'=>$build['source_sha256'],'build_id'=>$build['id'],'digests'=>$build['digests']]);$platform->work();$cert=$platform->read('certifications',$cert['id']);
    if($case==='valid'){if($cert['status']!=='passed'){throw new RuntimeException('declared_typed_resource_certification_failed');}}
    elseif($cert['status']!=='failed'||!str_contains($cert['_diagnostic']??'','conformance_unexpected_result:reference.refresh')){throw new RuntimeException('unsafe_resource_was_certified');}$assertions++;
}
echo json_encode(['result'=>'passed','assertions'=>$assertions,'cases'=>['undeclared_storage_rejected','wrong_value_type_rejected','declared_typed_storage_passed']],JSON_THROW_ON_ERROR)."\n";
