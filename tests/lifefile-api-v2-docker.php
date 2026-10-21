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
$pdf=base64_encode('%PDF-1.7'.str_repeat(' ',8388608-8));
$command=array_merge(['docker','run','--rm','-i'],UniScript\Integration\RuntimeProcess::flags(),['--env','PACKAGE_ROOT=/package','--env','EXECUTION_REVISION='.$build['execution_uuid'],'--env','SERVICE_TRANSPORT=stdio','--entrypoint','timeout','sha256:'.$build['digests']['executable_image_sha256'],'-s','KILL','30','php','-d','display_errors=0','/opt/kit/bin/invoke.php']);
$scope=['protocol_version'=>'1','invocation_id'=>'synthetic-invocation','command_id'=>'command','attempt_id'=>'attempt','correlation_id'=>'correlation','package_id'=>'uniscript/lifefile-v2','execution_revision'=>$build['execution_uuid'],'connection_id'=>'connection','organization_id'=>'organization','location_id'=>'location','environment'=>'local','actor_id'=>'actor','operation'=>'lifefile.api.execute','schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'synthetic-key','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+30),'request_budget'=>1,'service_grant'=>'synthetic-grant','input'=>['operation_id'=>'appendOrderDocumentPages','parameters'=>['external_reference_handle'=>'synthetic-reference'],'body'=>['pdfBase64'=>$pdf]]];
$result=UniScript\Integration\RuntimeProcess::invoke($command,$scope,static function(array $request)use($pdf):array{if(($request['arguments']['body']['pdfBase64']??null)!==$pdf){throw new RuntimeException('pdf_changed');}return ['status'=>'accepted','data'=>['type'=>'success']];});if($result->kind!=='success'){throw new RuntimeException('docker_8mib_pdf_write_failed');}
$scope['deadline']=gmdate('Y-m-d\TH:i:s\Z',time()+30);$scope['input']=['operation_id'=>'getOrderDocument','parameters'=>['external_reference_handle'=>'synthetic-reference']];
$result=UniScript\Integration\RuntimeProcess::invoke($command,$scope,static fn(array $request):array=>['status'=>'accepted','data'=>['type'=>'success','data'=>['pdfBase64'=>$pdf]]]);if($result->kind!=='success'||($result->data['response']['data']['pdfBase64']??null)!==$pdf){throw new RuntimeException('docker_8mib_pdf_read_failed');}
$deployment=$platform->enqueue('deployments',['deployment_uuid'=>'v2-deployment-'.bin2hex(random_bytes(8)),'execution_uuid'=>$build['execution_uuid'],'certification_id'=>$cert['id'],'environment'=>'local','digests'=>$build['digests']]);$platform->work();$deployment=$platform->read('deployments',$deployment['id']);
try{if($deployment['status']!=='ready'){throw new RuntimeException(json_encode($deployment));}echo json_encode(['result'=>'passed','operations'=>$cert['certification_summary']['operations'],'source_sha256'=>$build['source_sha256'],'image_sha256'=>$build['digests']['executable_image_sha256'],'document_round_trips'=>2,'maximum_document_bytes'=>8388608],JSON_THROW_ON_ERROR)."\n";}
finally{Process::run(['docker','rm','-f','uniscript-package-'.$deployment['id']],30);}
