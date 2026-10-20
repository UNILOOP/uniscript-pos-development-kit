<?php

declare(strict_types=1);
require __DIR__.'/callbacks.php';

$assertions=0;$httpState=$state.'-http';$configFile=$state.'-config.json';
file_put_contents($configFile,json_encode(['state'=>$httpState,'payload_key_hex'=>bin2hex($payloadKey),'replay_key_hex'=>bin2hex($replayKey),'endpoints'=>[$endpointId=>$endpoint]],JSON_THROW_ON_ERROR));chmod($configFile,0600);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-S',$address,dirname(__DIR__).'/bin/callback-receiver.php'],[0=>['pipe','r'],1=>['file',$state.'-http.log','a'],2=>['file',$state.'-http.log','a']],$pipes,dirname(__DIR__),array_merge(getenv(),['SYNTHETIC_CALLBACK_CONFIG'=>$configFile]));fclose($pipes[0]);
function http(string $path,string $data,array $headers=[]):array{global $address;
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",['Content-Type: application/json',...$headers])."\r\n",'content'=>$data,'timeout'=>5,'ignore_errors'=>true]]);$body=@file_get_contents('http://'.$address.$path,false,$ctx);$head=http_get_last_response_headers();preg_match('~\s(\d{3})\s~',$head[0]??'',$matches);return ['status'=>(int)($matches[1]??0),'data'=>$body===false?null:json_decode($body,true)];}
try{
    for($index=0;$index<100;$index++){if(http('/missing','')['status']!==0){break;}usleep(20000);}
    check(http('/callbacks/'.$endpointId,$body)['status']===422,'http unsigned callback blocked');
    $timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp.'.http-delivery.'.$body,$key);$headers=['X-Synthetic-Timestamp: '.$timestamp,'X-Synthetic-Delivery-Id: http-delivery','X-Synthetic-Signature: '.$signature];
    $received=http('/callbacks/'.$endpointId,$body,$headers);check($received['status']===202&&$received['data']['state']==='matched','http callback received');
    $receiptHandle=$received['data']['receipt_handle'];$duplicate=http('/callbacks/'.$endpointId,$body,$headers);check($duplicate['status']===200&&$duplicate['data']['duplicate']&&$duplicate['data']['receipt_handle']===$receiptHandle,'http duplicate stable receipt');
    check(http('/receipts/'.$receiptHandle.'/replay','')['status']===422,'http unauth replay denied');
    $localAuthority=new UniScript\Integration\SyntheticReplayAuthority($replayKey);$claims=['actor_id'=>'actor-http','receipt_handle'=>$receiptHandle,...$scope,'expected_revision'=>1];
    $wrong=$localAuthority->issue(array_replace($claims,['connection_id'=>'connection-other']));check(http('/receipts/'.$receiptHandle.'/replay','',['Authorization: Bearer '.$wrong])['status']===422,'http cross connection replay denied');
    $grant=$localAuthority->issue($claims);$replay=http('/receipts/'.$receiptHandle.'/replay','',['Authorization: Bearer '.$grant]);check($replay['status']===200&&$replay['data']['applied']===false&&$replay['data']['decoder_execution_revision']==='originating-release','http authorized replay');check($replay['data']['scope']['connection_id']==='connection-a','http body cannot override connection');
    check(http('/receipts/'.$receiptHandle.'/replay','',['Authorization: Bearer '.$grant])['status']===422,'http one use replay revision');
    $fixtureFile=$state.'-fixture.json';file_put_contents($fixtureFile,$body);chmod($fixtureFile,0600);
    $simulation=UniScript\Integration\Process::run([PHP_BINARY,dirname(__DIR__).'/bin/callback-simulator.php','--config',$configFile,'--endpoint',$endpointId,'--url','http://'.$address,'--delivery','simulated-cli','--file',$fixtureFile]);check(json_decode($simulation,true)['state']==='matched','real signed callback CLI');
}finally{proc_terminate($server);proc_close($server);}
$logs=file_get_contents($state.'-http.log');check(!str_contains($logs,'SYNTHETIC-SENSITIVE-MARKER')&&!str_contains($logs,$key)&&!str_contains($logs,bin2hex($replayKey)),'http logs exclude bodies and keys');
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
