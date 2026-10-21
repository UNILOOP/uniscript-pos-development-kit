<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$archive=$argv[1]??throw new InvalidArgumentException('source_zip_required');
$state=sys_get_temp_dir().'/lifefile-api-v2-platform-'.bin2hex(random_bytes(6));$platform=new Platform($state);
$build=$platform->enqueue('builds',['execution_uuid'=>'lifefile-api-v2-'.bin2hex(random_bytes(8)),'source_sha256'=>hash_file('sha256',$archive),'package_id'=>'uniscript/lifefile-v2','package_version'=>'2.0.0'],$archive);
$platform->work();$build=$platform->read('builds',$build['id']);if($build['status']!=='succeeded'){throw new RuntimeException(json_encode($build));}
$cert=$platform->enqueue('certifications',['execution_uuid'=>$build['execution_uuid'],'build_id'=>$build['id'],'source_sha256'=>$build['source_sha256'],'digests'=>$build['digests']]);$platform->work();$cert=$platform->read('certifications',$cert['id']);if($cert['status']!=='passed'||count($cert['certification_summary']['operations'])!==13){throw new RuntimeException(json_encode($cert));}
$deployment=$platform->enqueue('deployments',['deployment_uuid'=>'v2-deployment-'.bin2hex(random_bytes(8)),'execution_uuid'=>$build['execution_uuid'],'certification_id'=>$cert['id'],'environment'=>'local','digests'=>$build['digests']]);$platform->work();$deployment=$platform->read('deployments',$deployment['id']);
try{if($deployment['status']!=='ready'){throw new RuntimeException(json_encode($deployment));}echo json_encode(['result'=>'passed','operations'=>$cert['certification_summary']['operations'],'source_sha256'=>$build['source_sha256'],'image_sha256'=>$build['digests']['executable_image_sha256']],JSON_THROW_ON_ERROR)."\n";}
finally{Process::run(['docker','rm','-f','uniscript-package-'.$deployment['id']],30);}
