<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$archive=$argv[1]??throw new InvalidArgumentException('usage: php tests/lifefile-docker.php PACKAGE.zip');
$zip=new ZipArchive;if($zip->open($archive,ZipArchive::RDONLY|ZipArchive::CHECKCONS)!==true){throw new InvalidArgumentException('invalid_source_zip');}try{$manifest=UniScript\Integration\JsonDocument::decode($zip->getFromName('integration.json',65537),true);}finally{$zip->close();}
$state=sys_get_temp_dir().'/lifefile-platform-'.bin2hex(random_bytes(6));$platform=new Platform($state);
$build=$platform->enqueue('builds',['execution_uuid'=>'lifefile-'.bin2hex(random_bytes(8)),'source_sha256'=>hash_file('sha256',$archive),'package_id'=>'uniscript/lifefile','package_version'=>$manifest['version']],$archive);
$platform->work();$build=$platform->read('builds',$build['id']);
if($build['status']!=='succeeded'){throw new RuntimeException(json_encode($build,JSON_THROW_ON_ERROR));}
$cert=$platform->enqueue('certifications',['execution_uuid'=>$build['execution_uuid'],'build_id'=>$build['id'],'source_sha256'=>$build['source_sha256'],'digests'=>$build['digests']]);
$platform->work();$cert=$platform->read('certifications',$cert['id']);
if($cert['status']!=='passed'||count($cert['certification_summary']['operations'])!==11){throw new RuntimeException(json_encode($cert,JSON_THROW_ON_ERROR));}
$deployment=$platform->enqueue('deployments',['deployment_uuid'=>'lifefile-deployment-'.bin2hex(random_bytes(8)),'execution_uuid'=>$build['execution_uuid'],'certification_id'=>$cert['id'],'environment'=>'local','digests'=>$build['digests']]);
$platform->work();$deployment=$platform->read('deployments',$deployment['id']);
if($deployment['status']!=='ready'){throw new RuntimeException(json_encode($deployment,JSON_THROW_ON_ERROR));}
try{echo json_encode(['result'=>'passed','operations'=>$cert['certification_summary']['operations'],'host_services'=>$cert['certification_summary']['host_services'],'source_sha256'=>$build['source_sha256'],'image_sha256'=>$build['digests']['executable_image_sha256']],JSON_THROW_ON_ERROR)."\n";}
finally{Process::run(['docker','rm','-f',$deployment['_container']],30);}
