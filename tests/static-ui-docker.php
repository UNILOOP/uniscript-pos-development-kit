<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Manifest;
use UniScript\Integration\Platform;
use UniScript\Integration\Process;
use UniScript\Integration\StaticUi;

$count=0;function check(bool $ok):void{global $count;$count++;if(!$ok){throw new RuntimeException('assertion_failed_'.$count);}}
$archive=$argv[1]??throw new InvalidArgumentException('archive_required');$source=dirname(__DIR__).'/examples/custom-ui';$manifest=Manifest::read($source);$ui=StaticUi::inspect($source,$manifest);
$state=sys_get_temp_dir().'/custom-ui-platform-'.bin2hex(random_bytes(6));$platform=new Platform($state);$execution='custom-ui-'.bin2hex(random_bytes(8));
$build=$platform->enqueue('builds',['execution_uuid'=>$execution,'source_sha256'=>hash_file('sha256',$archive),'package_id'=>$manifest['package_id'],'package_version'=>$manifest['version']],$archive);$platform->work();$build=$platform->read('builds',$build['id']);check($build['status']==='succeeded');check($build['digests']['ui_sha256']===$ui['sha256']);
$cert=$platform->enqueue('certifications',['execution_uuid'=>$execution,'build_id'=>$build['id'],'source_sha256'=>$build['source_sha256'],'digests'=>$build['digests']]);$platform->work();$cert=$platform->read('certifications',$cert['id']);check($cert['status']==='passed');check(count($cert['certification_summary']['operations'])===count($manifest['operations']));
$deployment=$platform->enqueue('deployments',['deployment_uuid'=>'ui-deployment-'.bin2hex(random_bytes(8)),'execution_uuid'=>$execution,'certification_id'=>$cert['id'],'environment'=>'local','digests'=>$build['digests']]);$platform->work();$deployment=$platform->read('deployments',$deployment['id']);
try{
    check($deployment['status']==='ready');
    $forged=$build;$forged['id']=str_repeat('a',32);$forged['execution_uuid']='forged-ui-'.bin2hex(random_bytes(8));$forged['digests']['ui_sha256']=str_repeat('0',64);
    file_put_contents($state.'/builds/'.$forged['id'].'.json',json_encode($forged,JSON_THROW_ON_ERROR));copy($state.'/sources/'.$build['id'].'.zip',$state.'/sources/'.$forged['id'].'.zip');
    $bad=$platform->enqueue('certifications',['execution_uuid'=>$forged['execution_uuid'],'build_id'=>$forged['id'],'source_sha256'=>$build['source_sha256'],'digests'=>$forged['digests']]);$platform->work();$bad=$platform->read('certifications',$bad['id']);check($bad['status']==='failed');check(($bad['_diagnostic']??'')==='ui_artifact_mismatch');
    echo json_encode(['result'=>'passed','assertions'=>$count,'source_sha256'=>$build['source_sha256'],'image_sha256'=>$build['digests']['executable_image_sha256'],'ui_sha256'=>$ui['sha256'],'operations'=>$cert['certification_summary']['operations']],JSON_THROW_ON_ERROR)."\n";
}finally{if(isset($deployment['_container'])){Process::run(['docker','rm','-f',$deployment['_container']],30);}}
