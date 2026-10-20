<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Process;

if(is_executable('/Applications/Docker.app/Contents/Resources/bin/docker-credential-desktop')){putenv('PATH=/Applications/Docker.app/Contents/Resources/bin:'.getenv('PATH'));}
$root=dirname(__DIR__);$project='uniscript-clean-'.bin2hex(random_bytes(5));$assertions=0;$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
putenv('SDK_OPERATOR_PORT='.$port);putenv('SDK_DEV_TAG='.$project);
$compose=['docker','compose','-f',$root.'/compose.dev.yaml','-p',$project];
function check(bool $value,string $reason):void{global $assertions;$assertions++;if(!$value){throw new RuntimeException('clean_workflow_failed:'.$reason);}}
function run(array $arguments,int $timeout=180):string{global $compose;return Process::run([...$compose,...$arguments],$timeout);}
$result=null;
try{
    run(['build','init'],240);run(['up','-d','--no-build'],120);
    $summary=run(['exec','-T','operator','php','bin/dev-workflow.php','--example','demo'],240);$result=json_decode($summary,true,32,JSON_THROW_ON_ERROR);check($result['result']==='passed'&&$result['assertions']>=16,'real lifecycle and vendor workflow');check($result['new_vendor_orders']===2&&$result['unknown_outcome_reconciled']===true,'accepted/lost/reconcile never duplicates vendor orders');check($result['callback_fixture_auth_replay']==='passed','real authenticated callback fixture');
    $operator=trim(run(['ps','-q','operator']));$op=json_decode(Process::run(['docker','inspect',$operator]),true,32,JSON_THROW_ON_ERROR)[0];check($op['HostConfig']['ReadonlyRootfs']&&$op['Config']['User']==='10001:10001','operator nonroot read-only');check(($op['HostConfig']['PortBindings']['8080/tcp'][0]['HostIp']??null)==='127.0.0.1','operator loopback only');
    foreach(['operator','broker','mock-pos','callbacks'] as $role){$id=trim(run(['ps','-q',$role]));$inspection=json_decode(Process::run(['docker','inspect',$id]),true,32,JSON_THROW_ON_ERROR)[0];foreach($inspection['Mounts'] as $mount){check($mount['Type']==='volume','no host path/socket mount in '.$role);}}
    $probe=run(['exec','-T','operator','php','-r','echo json_encode(["app"=>is_dir("/opt/kit/app"),"socket"=>file_exists("/var/run/docker.sock"),"privatevar"=>is_dir("/opt/kit/var")]);']);check(json_decode($probe,true)===['app'=>false,'socket'=>false,'privatevar'=>false],'clean tool image excludes core source/private state/socket');
    $deployments=json_decode(run(['exec','-T','worker','php','-r','$files=glob("/state/platform/deployments/*.json");echo json_encode(array_map(static fn($f)=>json_decode(file_get_contents($f),true)["_container"],$files));']),true);
    check(count($deployments)===1,'one pinned runtime deployment');$runtime=json_decode(Process::run(['docker','inspect',$deployments[0]]),true,32,JSON_THROW_ON_ERROR)[0];check($runtime['Mounts']===[]&&$runtime['HostConfig']['NetworkMode']==='none'&&$runtime['HostConfig']['ReadonlyRootfs']&&$runtime['Config']['User']==='10001:10001','package runtime isolation');
    $runtimeKeys=array_map(static fn(string $entry):string=>explode('=',$entry,2)[0],$runtime['Config']['Env']);check(array_intersect($runtimeKeys,['BUILDER_TOKEN','CERTIFIER_TOKEN','DEPLOYER_TOKEN','RUNTIME_TOKEN','BROKER_ADMIN_TOKEN','MOCK_POS_PASSWORD'])===[],'package receives no platform credentials');
    $html=@file_get_contents('http://127.0.0.1:'.$port.'/');check(is_string($html)&&str_contains($html,'Run demo workflow')&&str_contains($html,'Synthetic data'),'operator UI reachable without PHP on host');
    $badContext=stream_context_create(['http'=>['header'=>"Host: evil.example\r\n",'ignore_errors'=>true]]);$bad=@file_get_contents('http://127.0.0.1:'.$port.'/',false,$badContext);check($bad==='Local operator host required.','DNS rebinding host rejected');
    $badPost=stream_context_create(['http'=>['method'=>'POST','content'=>'example=demo','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'ignore_errors'=>true]]);$bad=@file_get_contents('http://127.0.0.1:'.$port.'/',false,$badPost);check(str_contains($bad,'local session is invalid'),'operator action requires session CSRF');
}finally{
    try{run(['exec','-T','worker','php','bin/dev-cleanup.php'],60);}catch(Throwable){}
    try{run(['down','--volumes','--remove-orphans'],120);}catch(Throwable){}
}
echo json_encode(['result'=>'passed','assertions'=>$assertions,'project'=>$project,'workflow_assertions'=>$result['assertions']??null],JSON_THROW_ON_ERROR)."\n";
