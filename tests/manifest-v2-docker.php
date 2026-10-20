<?php

declare(strict_types=1);

require __DIR__.'/manifest-v2.php';

use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$manifest=$original;$manifest['manifest_version']=2;$manifest['configuration_schema']='schemas/configuration.json';$manifest['credential_schema']='schemas/credential.json';$manifest['resource_schemas']=(object)['state'=>'schemas/state.json'];writeManifest($manifest);
$archive=$root.'/v2.zip';Process::run([PHP_BINARY,dirname(__DIR__).'/bin/package.php',$root,$archive]);
$state=sys_get_temp_dir().'/kit-v2-docker-'.bin2hex(random_bytes(8));$platform=new Platform($state);
$build=$platform->enqueue('builds',['execution_uuid'=>'v2-'.bin2hex(random_bytes(8)),'source_sha256'=>hash_file('sha256',$archive),'package_id'=>'uniscript/starter','package_version'=>'1.0.0'],$archive);
$platform->work();$build=$platform->read('builds',$build['id']);check($build['status']==='succeeded','actual v2 image build:'.($build['_diagnostic']??''));
$cert=$platform->enqueue('certifications',['execution_uuid'=>$build['execution_uuid'],'build_id'=>$build['id'],'source_sha256'=>$build['source_sha256'],'digests'=>$build['digests']]);$platform->work();$cert=$platform->read('certifications',$cert['id']);check($cert['status']==='passed','actual v2 external certification:'.($cert['_diagnostic']??''));
$deployment=$platform->enqueue('deployments',['deployment_uuid'=>'v2-deployment-'.bin2hex(random_bytes(8)),'execution_uuid'=>$build['execution_uuid'],'certification_id'=>$cert['id'],'environment'=>'local','digests'=>$build['digests']]);$platform->work();$deployment=$platform->read('deployments',$deployment['id']);
try{check($deployment['status']==='ready','actual v2 runtime readiness:'.($deployment['_diagnostic']??''));echo json_encode(['result'=>'passed','assertions'=>$assertions,'manifest_version'=>2,'image_sha256'=>$build['digests']['executable_image_sha256']],JSON_THROW_ON_ERROR)."\n";}
finally{Process::run(['docker','rm','-f','uniscript-package-'.$deployment['id']],30);}
