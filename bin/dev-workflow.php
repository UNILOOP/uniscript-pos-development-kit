<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Invocation;
use UniScript\Integration\Manifest;
use UniScript\Integration\Process;
use UniScript\Integration\Result;
use UniScript\Integration\SyntheticReplayAuthority;

$config=JsonDocumentAlias();
function JsonDocumentAlias():array{return UniScript\Integration\JsonDocument::decode(file_get_contents('/config/environment.json'),true);}
function http(string $url,string $method='GET',?array $data=null,?string $token=null,?string $multipart=null,?string $boundary=null,array $headers=[]):array{
    $content=$multipart??($data===null?'':json_encode($data,JSON_THROW_ON_ERROR));
    $head=['Content-Type: '.($multipart===null?'application/json':'multipart/form-data; boundary='.$boundary),...$headers];if($token!==null){$head[]='Authorization: Bearer '.$token;}
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$head)."\r\n",'content'=>$content,'timeout'=>40,'follow_location'=>0,'ignore_errors'=>true]]);$response=@file_get_contents($url,false,$context,0,1048577);$status=http_get_last_response_headers();
    if($response===false||strlen($response)>1048576||!isset($status[0])||!preg_match('~\s2\d\d\s~',$status[0])){throw new RuntimeException('local_http_request_failed');}
    $decoded=UniScript\Integration\JsonDocument::decode($response,true);if(!is_array($decoded)){throw new RuntimeException('local_response_invalid');}return $decoded;
}
function poll(string $kind,array $record,string $token,string $success):array{global $config;$started=time();do{if($record['status']===$success){return $record;}if($record['status']==='failed'||time()-$started>180){throw new RuntimeException('local_lifecycle_failed:'.$kind); }usleep(200000);$record=http($config['RUNTIME_URL'].'/v1/'.$kind.'/'.$record['id'],token:$token);}while(true);}
$options=getopt('',['example:']);$example=$options['example']??'demo';if(!in_array($example,['starter','demo'],true)){throw new InvalidArgumentException('unknown_example');}
$root=dirname(__DIR__);$manifest=Manifest::read($root.'/examples/'.$example);$execution='local-'.bin2hex(random_bytes(12));$archive='/artifacts/'.$example.'-'.$execution.'.zip';Process::run([PHP_BINARY,$root.'/bin/package.php',$root.'/examples/'.$example,$archive]);
$fields=['execution_uuid'=>$execution,'source_sha256'=>hash_file('sha256',$archive),'package_id'=>$manifest['package_id'],'package_version'=>$manifest['version']];$boundary='kit-'.bin2hex(random_bytes(12));$multipart='';foreach($fields as $key=>$value){$multipart.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".$value."\r\n";}$multipart.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"archive\"; filename=\"package.zip\"\r\nContent-Type: application/zip\r\n\r\n".file_get_contents($archive)."\r\n--".$boundary."--\r\n";
$build=poll('builds',http($config['RUNTIME_URL'].'/v1/builds','POST',token:$config['BUILDER_TOKEN'],multipart:$multipart,boundary:$boundary),$config['BUILDER_TOKEN'],'succeeded');
$cert=poll('certifications',http($config['RUNTIME_URL'].'/v1/certifications','POST',['execution_uuid'=>$execution,'build_id'=>$build['id'],'source_sha256'=>$fields['source_sha256'],'digests'=>$build['digests']],$config['CERTIFIER_TOKEN']),$config['CERTIFIER_TOKEN'],'passed');
$deployment=poll('deployments',http($config['RUNTIME_URL'].'/v1/deployments','POST',['deployment_uuid'=>'deployment-'.bin2hex(random_bytes(12)),'execution_uuid'=>$execution,'certification_id'=>$cert['id'],'environment'=>'local','digests'=>$build['digests']],$config['DEPLOYER_TOKEN']),$config['DEPLOYER_TOKEN'],'ready');
$assertions=3;
function invoke(string $operation,array $input,string $key,string $expected):array{global $manifest,$execution,$config,$assertions,$root;
    $envelope=['protocol_version'=>'1','invocation_id'=>'invocation-'.bin2hex(random_bytes(12)),'command_id'=>'command-'.bin2hex(random_bytes(12)),'attempt_id'=>'attempt-'.bin2hex(random_bytes(12)),'correlation_id'=>'correlation-'.bin2hex(random_bytes(12)),'package_id'=>$manifest['package_id'],'execution_revision'=>$execution,'organization_id'=>'synthetic-organization','location_id'=>'synthetic-location','connection_id'=>'synthetic-connection-'.$execution,'environment'=>'sandbox','deployment_environment'=>'local','actor_id'=>'synthetic-operator','operation'=>$operation,'schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>$key,'deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+30),'request_budget'=>10,'input'=>$input,'service_grant'=>bin2hex(random_bytes(32))];Invocation::fromArray($envelope);
    $resources=[];foreach($manifest['resource_schemas']??[] as $name=>$path){$resources[$name]=json_decode(file_get_contents($root.'/examples/'.($manifest['package_id']==='uniscript/demo'?'demo':'starter').'/'.$path),true,32,JSON_THROW_ON_ERROR);}
    http($config['BROKER_URL'].'/grants','POST',['invocation'=>$envelope,'resources'=>$resources],$config['BROKER_ADMIN_TOKEN']);$result=Result::fromArray(http($config['RUNTIME_URL'].'/v1/invocations','POST',$envelope,$config['RUNTIME_TOKEN']));if($result->kind!==$expected){throw new RuntimeException('local_invocation_failed:'.$operation.':'.$result->kind);}$assertions++;return $result->jsonSerialize();
}
invoke('identity.describe',[],'identity-'.$execution,'success');invoke('health.probe',$example==='demo'?['config'=>['vendor_id'=>'10001','location_id'=>'10002','api_network_id'=>'10003']]:[],'health-'.$execution,'success');invoke('orders.validate',[],'invalid-'.$execution,'validation_rejection');
$baseline=null;
if($example==='demo'){
    $scope=['package_id'=>$manifest['package_id'],'organization_id'=>'synthetic-organization','location_id'=>'synthetic-location','connection_id'=>'synthetic-connection-'.$execution,'environment'=>'sandbox'];$vendorScope=hash('sha256',json_encode($scope,JSON_THROW_ON_ERROR));
    $metricHeaders=['Authorization: Basic '.base64_encode($config['MOCK_POS_USER'].':'.$config['MOCK_POS_PASSWORD']),'X-Location-Id: '.$vendorScope];
    $baseline=http('http://mock-pos:8080/metrics',headers:$metricHeaders)['orders'];
}
$accepted=invoke('orders.submit',['order_id'=>'synthetic-order'],'order-'.$execution,'remote_acceptance');
$duplicate=invoke('orders.submit',['order_id'=>'synthetic-order'],'order-'.$execution,'remote_acceptance');if($accepted['data']['external_reference_handle']!==$duplicate['data']['external_reference_handle']){throw new RuntimeException('local_idempotency_failed');}$assertions++;
$summary=['result'=>'passed','example'=>$example,'execution_uuid'=>$execution,'source_sha256'=>$fields['source_sha256'],'image_sha256'=>$build['digests']['executable_image_sha256'],'certified_operations'=>$cert['certification_summary']['operations'],'external_reference_handle'=>$accepted['data']['external_reference_handle']];
if($example==='demo'){
    invoke('orders.submit',['order_id'=>'synthetic-order','scenario'=>'accepted_then_lost'],'lost-'.$execution,'unknown_remote_outcome');
    invoke('orders.submit',['order_id'=>'synthetic-order','scenario'=>'accepted_then_lost'],'lost-'.$execution,'unknown_remote_outcome');
    invoke('orders.reconcile',[],'lost-'.$execution,'remote_acceptance');
    invoke('catalog.preview',[],'catalog-'.$execution,'success');
    $reference=invoke('reference.refresh',['expected_revision'=>0],'reference-'.$execution,'success');if($reference['data']['revision']!==1){throw new RuntimeException('typed_reference_revision_failed');}$assertions++;
    $receiptBody=json_encode(['external_reference'=>'synthetic-vendor-order','events'=>[['eventId'=>'event-'.$execution,'orderId'=>'synthetic-vendor-order','rxStatus'=>'Filled']]],JSON_THROW_ON_ERROR);$timestamp=(string)time();$delivery='callback-'.$execution;$signature=hash_hmac('sha256',$timestamp.'.'.$delivery.'.'.$receiptBody,$config['CALLBACK_SIGNING_KEY']);$headers=['X-Synthetic-Timestamp: '.$timestamp,'X-Synthetic-Delivery-Id: '.$delivery,'X-Synthetic-Signature: '.$signature];
    $callbackInput=json_decode($receiptBody,true);$receipt=http($config['CALLBACK_URL'].'/callbacks/'.$config['CALLBACK_ENDPOINT_ID'],'POST',$callbackInput,headers:$headers);
    if($receipt['state']!=='matched'){throw new RuntimeException('local_callback_fixture_not_matched');}$assertions++;
    $claims=['actor_id'=>'synthetic-operator','receipt_handle'=>$receipt['receipt_handle'],'package_id'=>'uniscript/demo','organization_id'=>'synthetic-organization','location_id'=>'synthetic-location','connection_id'=>'synthetic-connection','environment'=>'sandbox','expected_revision'=>1];$grant=(new SyntheticReplayAuthority(hex2bin($config['REPLAY_KEY_HEX'])))->issue($claims);$replay=http($config['CALLBACK_URL'].'/receipts/'.$receipt['receipt_handle'].'/replay','POST',token:$grant);
    if($replay['applied']!==false||$replay['decoder_execution_revision']!=='synthetic-original-decoder'){throw new RuntimeException('local_callback_replay_failed');}$assertions++;
    $after=http('http://mock-pos:8080/metrics',headers:$metricHeaders)['orders'];if($after-$baseline!==2){throw new RuntimeException('local_vendor_duplicate_submission');}$assertions++;$summary['new_vendor_orders']=2;
    $summary['unknown_outcome_reconciled']=true;$summary['callback_fixture_auth_replay']='passed';
}
$summary['assertions']=$assertions;echo json_encode($summary,JSON_THROW_ON_ERROR)."\n";
