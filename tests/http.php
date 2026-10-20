<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Process;

$root=dirname(__DIR__);$state=sys_get_temp_dir().'/kit-http-'.bin2hex(random_bytes(5));mkdir($state,0700);
$tokens=[];foreach(['BUILDER_TOKEN','CERTIFIER_TOKEN','DEPLOYER_TOKEN','RUNTIME_TOKEN'] as $role){$tokens[$role]=bin2hex(random_bytes(32));}
$socket=stream_socket_server('tcp://127.0.0.1:0',$code,$message);$address=stream_socket_get_name($socket,false);fclose($socket);
$environment=array_merge(getenv(),$tokens,['PLATFORM_STATE'=>$state]);
$server=proc_open([PHP_BINARY,'-d','upload_max_filesize=20M','-d','post_max_size=22M','-S',$address,$root.'/bin/control-plane.php'],[0=>['pipe','r'],1=>['file',$state.'/server.log','a'],2=>['file',$state.'/server.log','a']],$pipes,$root,$environment);
fclose($pipes[0]);$assertions=0;
function check(bool $condition,string $reason):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException('assertion_failed:'.$reason);}}
function request(string $path,string $method,string $token='',string $body='',string $contentType='application/json'):array{global $address;
    $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>"Authorization: Bearer ".$token."\r\nContent-Type: ".$contentType."\r\n",'content'=>$body,'timeout'=>5,'ignore_errors'=>true]]);
    $response=@file_get_contents('http://'.$address.$path,false,$ctx);$headers=http_get_last_response_headers();preg_match('~\s(\d{3})\s~',$headers[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>$response===false?null:json_decode($response,true)];}
try{
    for($i=0;$i<100;$i++){if(request('/missing','GET')['status']===404){break;}usleep(20000);}
    check(request('/v1/builds','GET')['status']===401,'unauthorized');
    check(request('/v1/builds','POST',$tokens['CERTIFIER_TOKEN'])['status']===401,'role_separation');
    $zip=$state.'/starter.zip';Process::run([PHP_BINARY,$root.'/bin/package.php',$root.'/examples/starter',$zip]);
    $fields=['execution_uuid'=>'http-test-execution','source_sha256'=>hash_file('sha256',$zip),'package_id'=>'uniscript/starter','package_version'=>'1.0.0'];$boundary='kit-'.bin2hex(random_bytes(12));$body='';
    foreach($fields as $name=>$value){$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".$value."\r\n";}
    $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"archive\"; filename=\"starter.zip\"\r\nContent-Type: application/zip\r\n\r\n".file_get_contents($zip)."\r\n--".$boundary."--\r\n";
    $first=request('/v1/builds','POST',$tokens['BUILDER_TOKEN'],$body,'multipart/form-data; boundary='.$boundary);check($first['status']===202&&$first['body']['status']==='queued','multipart_queued');
    $second=request('/v1/builds','POST',$tokens['BUILDER_TOKEN'],$body,'multipart/form-data; boundary='.$boundary);check($first['body']['id']===$second['body']['id'],'http_idempotency');
    check(!isset($first['body']['_input'])&&!isset($first['body']['_fingerprint']),'private_internal_state');
    check(request('/v1/builds/'.$first['body']['id'],'GET',$tokens['BUILDER_TOKEN'])['body']['source_sha256']===$fields['source_sha256'],'get_build');
    check(request('/v1/builds/'.$first['body']['id'],'GET',$tokens['DEPLOYER_TOKEN'])['status']===401,'read_role_separation');
    $conflict=str_replace('name="package_version"'."\r\n\r\n1.0.0",'name="package_version"'."\r\n\r\n1.0.1",$body);
    check(request('/v1/builds','POST',$tokens['BUILDER_TOKEN'],$conflict,'multipart/form-data; boundary='.$boundary)['status']===422,'idempotency_conflict');
    check(request('/v1/deployments','POST',$tokens['DEPLOYER_TOKEN'],json_encode(['deployment_uuid'=>'deployment-production','execution_uuid'=>'http-test-execution','certification_id'=>'00000000000000000000000000000000','environment'=>'production','digests'=>[]]))['status']===422,'production_disabled');
    check(request('/v1/invocations','POST',$tokens['BUILDER_TOKEN'],'{}')['status']===401,'runtime_role_separation');
    check(request('/v1/invocations','POST',$tokens['RUNTIME_TOKEN'],'{}')['status']===422,'invalid_envelope');
}finally{proc_terminate($server);proc_close($server);}
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
