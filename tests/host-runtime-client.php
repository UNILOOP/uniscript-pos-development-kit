<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\HostRuntimeClient;

$assertions=0;function check(bool $condition,string $message):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException($message);}}
function denied(callable $operation):void{try{$operation();}catch(Throwable){check(true,'denied');return;}check(false,'expected denial');}
$state=sys_get_temp_dir().'/kit-host-client-'.bin2hex(random_bytes(6));mkdir($state,0700);$token=bin2hex(random_bytes(32));
$router=<<<'ROUTER'
<?php
header('Content-Type: application/json');
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer '.getenv('EXPECTED_TOKEN')){http_response_code(401);echo '{}';return;}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/v1/invocations'){http_response_code(404);echo '{}';return;}
$invocation=json_decode(file_get_contents('php://input'),true);$scenario=$invocation['input']['scenario']??'accepted';$file=getenv('COUNTS_FILE');$counts=is_file($file)?json_decode(file_get_contents($file),true):[];$counts[$scenario]=($counts[$scenario]??0)+1;file_put_contents($file,json_encode($counts));
if($scenario==='accepted_then_lost'){http_response_code(502);echo 'SYNTHETIC-SECRET-VENDOR-BODY';return;}
if($scenario==='invalid_result'){echo '{"protocol_version":"1","kind":"remote_acceptance","reason_code":"accepted","data":{"private":"SYNTHETIC-SECRET-VENDOR-BODY"},"field_errors":[],"recovery":[],"unknown_field":true}';return;}
echo json_encode(['protocol_version'=>'1','kind'=>'remote_acceptance','reason_code'=>'order.accepted','data'=>['external_reference_handle'=>'opaque-reference','private_vendor_body'=>'SYNTHETIC-SECRET-VENDOR-BODY'],'field_errors'=>[],'recovery'=>[]]);
ROUTER;
file_put_contents($state.'/router.php',$router);$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,$state.'/router.php'],[0=>['pipe','r'],1=>['file',$state.'/server.log','a'],2=>['file',$state.'/server.log','a']],$pipes,$state,array_merge(getenv(),['EXPECTED_TOKEN'=>$token,'COUNTS_FILE'=>$state.'/counts.json']));fclose($pipes[0]);
$env=['protocol_version'=>'1','request_budget'=>2,'input'=>[],'service_grant'=>'SYNTHETIC-SCOPED-GRANT'];
foreach(['invocation_id','command_id','attempt_id','correlation_id','package_id','execution_revision','connection_id','organization_id','location_id','operation','schema_version','routing_revision','configuration_revision','mapping_revision','credential_revision','idempotency_key','actor_id'] as $key){$env[$key]=$key==='package_id'?'uniscript/demo':($key==='operation'?'orders.submit':$key);}$env['environment']='sandbox';$env['deployment_environment']='local';$env['deadline']=gmdate('Y-m-d\TH:i:s\Z',time()+30);
try{
    for($index=0;$index<100;$index++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.05);if($socket!==false){fclose($socket);break;}usleep(20000);}
    $client=new HostRuntimeClient('http://'.$address,$token);$accepted=$client->invoke($env,'remote_acceptance');check($accepted->data['external_reference_handle']==='opaque-reference','real HTTP runtime result');
    $lost=$client->invoke(array_replace($env,['input'=>['scenario'=>'accepted_then_lost']]),'unknown_remote_outcome');check($lost->recovery['resubmit_allowed']===false,'transport loss blocks resubmit');
    $invalid=$client->invoke(array_replace($env,['input'=>['scenario'=>'invalid_result']]),'unknown_remote_outcome');check($invalid->reasonCode==='runtime.transport_lost','strict result rejects unknown fields');
    denied(fn()=>$client->invoke($env,'success'));denied(fn()=>new HostRuntimeClient('http://external.example',$token));denied(fn()=>new HostRuntimeClient('http://user:secret@127.0.0.1',$token));
    $cli=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/runtime-check.php','--expect','remote_acceptance'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),array_merge(getenv(),['PACKAGE_RUNTIME_URL'=>'http://'.$address,'PACKAGE_RUNTIME_TOKEN'=>$token]));fwrite($pipes[0],json_encode($env,JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($cli)===0,'CLI fixture success');check(json_decode($output,true)['external_reference_handle']==='opaque-reference','CLI opaque reference proof');check(!str_contains($output.$errors,'SYNTHETIC-SECRET-VENDOR-BODY')&&!str_contains($output.$errors,$env['service_grant'])&&!str_contains($output.$errors,$token),'CLI does not log bodies or grants');
    $counts=json_decode(file_get_contents($state.'/counts.json'),true);check($counts===['accepted'=>3,'accepted_then_lost'=>1,'invalid_result'=>1],'each call makes exactly one request');
}finally{proc_terminate($process);proc_close($process);}
$logs=file_get_contents($state.'/server.log');check(!str_contains($logs,'SYNTHETIC-SECRET-VENDOR-BODY')&&!str_contains($logs,$token)&&!str_contains($logs,$env['service_grant']),'request logs contain no sensitive payload');
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
