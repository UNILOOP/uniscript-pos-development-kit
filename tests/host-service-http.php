<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Platform;

$assertions=0;
function check(bool $condition,string $reason):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException('assertion_failed:'.$reason);}}
$root=dirname(__DIR__);$state=sys_get_temp_dir().'/kit-host-service-http-'.bin2hex(random_bytes(6));mkdir($state,0700);mkdir($state.'/bin',0700);mkdir($state.'/deployments',0700);
$grant=bin2hex(random_bytes(32));$digest=str_repeat('a',64);
$router=<<<'ROUTER'
<?php
if(($_SERVER['HTTP_USER_AGENT']??'')===''){http_response_code(403);echo '<html>Forbidden</html>';return;}
header('Content-Type: application/json');
$request=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
$record=['user_agent'=>$_SERVER['HTTP_USER_AGENT']??null,'accept'=>$_SERVER['HTTP_ACCEPT']??null,'content_type'=>$_SERVER['CONTENT_TYPE']??null,'invocation_id'=>$request['invocation_id']??null,'grant_sha256'=>hash('sha256',(string)($request['grant']??'')),'service'=>$request['service']??null,'action'=>$request['action']??null,'authorization_absent'=>!isset($_SERVER['HTTP_AUTHORIZATION'])];
file_put_contents(getenv('CAPTURE_FILE'),json_encode($record)."\n",FILE_APPEND);
if(!hash_equals(hash('sha256',getenv('EXPECTED_GRANT')),$record['grant_sha256'])){http_response_code(403);echo '{}';return;}
echo json_encode(['status'=>'accepted','external_reference_handle'=>'opaque-reference']);
ROUTER;
$docker=<<<'DOCKER'
<?php
if(($argv[1]??'')==='inspect'){echo 'sha256:'.str_repeat('a',64);exit;}
if(($argv[1]??'')!=='run'){exit;}
if(!in_array('none',$argv,true)||!in_array('--read-only',$argv,true)||!in_array('10001:10001',$argv,true)){exit(9);}
$envelope=json_decode(fgets(STDIN),true,32,JSON_THROW_ON_ERROR);
echo json_encode(['service_request'=>['invocation_id'=>$envelope['invocation_id'],'grant'=>$envelope['service_grant'],'service'=>'http','action'=>'request','arguments'=>['profile'=>'synthetic.reconcile','operation_key'=>$envelope['operation'],'parameters'=>[],'idempotency_key'=>$envelope['idempotency_key']]]])."\n";fflush(STDOUT);
$reply=json_decode(fgets(STDIN),true,32,JSON_THROW_ON_ERROR);
echo json_encode(['protocol_version'=>'1','kind'=>'success','reason_code'=>'http.observed','data'=>['accepted'=>($reply['response']['status']??null)==='accepted'],'field_errors'=>[],'recovery'=>[]])."\n";
DOCKER;
file_put_contents($state.'/router.php',$router);file_put_contents($state.'/bin/docker','#!'.PHP_BINARY."\n".$docker);chmod($state.'/bin/docker',0700);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-S',$address,$state.'/router.php'],[0=>['pipe','r'],1=>['file',$state.'/server.log','a'],2=>['file',$state.'/server.log','a']],$pipes,$root,array_merge(getenv(),['EXPECTED_GRANT'=>$grant,'CAPTURE_FILE'=>$state.'/capture.jsonl']));fclose($pipes[0]);
$env=['protocol_version'=>'1','invocation_id'=>'invocation-http','command_id'=>'command-a','attempt_id'=>'attempt-a','correlation_id'=>'correlation-a','package_id'=>'uniscript/demo','execution_revision'=>'execution-http','connection_id'=>'connection-a','organization_id'=>'organization-a','location_id'=>'location-a','environment'=>'local','actor_id'=>'synthetic-actor','operation'=>'orders.reconcile','schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'request-http','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+30),'request_budget'=>10,'input'=>[],'service_grant'=>$grant];
$previousPath=getenv('PATH');$previousEndpoint=getenv('HOST_SERVICES_URL');
try{
    for($index=0;$index<100;$index++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.05);if($socket!==false){fclose($socket);break;}usleep(20000);}
    $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true]]);@file_get_contents('http://'.$address,false,$context);$headers=http_get_last_response_headers();check(str_contains($headers[0]??'','403'),'missing user agent reproduces managed WAF rejection');
    file_put_contents($state.'/deployments/test.json',json_encode(['status'=>'ready','execution_uuid'=>$env['execution_revision'],'_package_id'=>$env['package_id'],'environment'=>'local','_operations'=>['orders.reconcile'],'_container'=>'test-runtime','executable_image_sha256'=>$digest]));
    putenv('PATH='.$state.'/bin:'.$previousPath);putenv('HOST_SERVICES_URL=http://'.$address.'/host-services');
    $result=(new Platform($state))->invoke($env);check($result->kind==='success'&&($result->data['accepted']??false)===true,'trusted supervisor performs real host HTTP request');
    $process=proc_open([PHP_BINARY,$root.'/bin/invoke.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,array_merge(getenv(),['PACKAGE_ROOT'=>$root.'/examples/demo','EXECUTION_REVISION'=>$env['execution_revision'],'SERVICE_TRANSPORT'=>'http']));
    fwrite($pipes[0],json_encode($env)."\n");fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0,'fallback invocation process succeeds');$result=json_decode($output,true,32,JSON_THROW_ON_ERROR);check(($result['kind']??null)==='remote_acceptance','fallback performs real host HTTP request');
    $captures=array_map(static fn(string $line):array=>json_decode($line,true,32,JSON_THROW_ON_ERROR),file($state.'/capture.jsonl',FILE_IGNORE_NEW_LINES));check(count($captures)===2,'both transports make exactly one host request');
    foreach($captures as $capture){check($capture['user_agent']==='UniScript-Package-Runtime/1','runtime identifies requests');check($capture['accept']==='application/json','host errors stay JSON');check($capture['content_type']==='application/json','JSON content type retained');check($capture['invocation_id']===$env['invocation_id']&&hash_equals(hash('sha256',$grant),$capture['grant_sha256']),'scoped invocation grant is unchanged');check($capture['service']==='http'&&$capture['action']==='request'&&$capture['authorization_absent'],'service identity retained without reusable bearer token');}
    foreach(['src/Platform.php','bin/invoke.php'] as $file){$source=file_get_contents($root.'/'.$file);check(str_contains($source,"'verify_peer'=>true")||str_contains($source,"'verify_peer' => true"),'TLS peer verification retained');check(str_contains($source,"'verify_peer_name'=>true")||str_contains($source,"'verify_peer_name' => true"),'TLS hostname verification retained');check(str_contains($source,"'follow_location'=>0")||str_contains($source,"'follow_location' => 0"),'redirects remain disabled');}
    check(!str_contains($output.$errors.file_get_contents($state.'/server.log'),$grant),'logs never disclose scoped grant');
}finally{putenv('PATH='.$previousPath);putenv($previousEndpoint===false?'HOST_SERVICES_URL':'HOST_SERVICES_URL='.$previousEndpoint);proc_terminate($server);proc_close($server);}
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
