<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$state=sys_get_temp_dir().'/uniscript-platform-test-'.bin2hex(random_bytes(6));
$platform=new Platform($state);$root=dirname(__DIR__);$assertions=0;
$hostSentinel=$state.'/host-only-synthetic-fixture';
file_put_contents($hostSentinel,'synthetic-host-only-fixture',LOCK_EX);
function check(bool $condition,string $reason):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException('assertion_failed:'.$reason);}}
function successful(array $record,string $status):void{check($record['status']===$status,json_encode($record,JSON_THROW_ON_ERROR));}
$archive=$state.'/demo.zip';
Process::run([PHP_BINARY,$root.'/bin/package.php',$root.'/examples/demo',$archive]);
$input=['execution_uuid'=>'docker-test-'.bin2hex(random_bytes(8)),'source_sha256'=>hash_file('sha256',$archive),'package_id'=>'uniscript/demo','package_version'=>json_decode(file_get_contents($root.'/examples/demo/integration.json'),true)['version']];
$build=$platform->enqueue('builds',$input,$archive);check($platform->enqueue('builds',$input,$archive)['id']===$build['id'],'idempotent_build');
$platform->work();$build=$platform->read('builds',$build['id']);successful($build,'succeeded');
$cert=$platform->enqueue('certifications',['execution_uuid'=>$input['execution_uuid'],'build_id'=>$build['id'],'source_sha256'=>$input['source_sha256'],'digests'=>$build['digests']]);
$platform->work();$cert=$platform->read('certifications',$cert['id']);successful($cert,'passed');check(count($cert['certification_summary']['operations'])===9,'certified_declared_handlers');check(in_array('http.request',$cert['certification_summary']['host_services'],true),'certified_observed_http');
$deployment=$platform->enqueue('deployments',['deployment_uuid'=>'deployment-test-'.bin2hex(random_bytes(8)),'execution_uuid'=>$input['execution_uuid'],'certification_id'=>$cert['id'],'environment'=>'local','digests'=>$build['digests']]);
$platform->work();$deployment=$platform->read('deployments',$deployment['id']);successful($deployment,'ready');
try{
    $container=$deployment['_container'];$inspection=json_decode(Process::run(['docker','inspect',$container]),true,32,JSON_THROW_ON_ERROR)[0];
    check($inspection['HostConfig']['ReadonlyRootfs']===true,'readonly_root');check($inspection['HostConfig']['NetworkMode']==='none','network_none');check($inspection['Config']['User']==='10001:10001','nonroot');check($inspection['Mounts']===[],'no_host_mounts');check($inspection['HostConfig']['CapDrop']===['ALL'],'capabilities_dropped');
    $env=['protocol_version'=>'1','invocation_id'=>'invocation-docker','command_id'=>'command-docker','attempt_id'=>'attempt-docker','correlation_id'=>'correlation-docker','package_id'=>'uniscript/demo','execution_revision'=>$input['execution_uuid'],'connection_id'=>'connection-docker','organization_id'=>'organization-docker','location_id'=>'location-docker','environment'=>'sandbox','deployment_environment'=>'local','actor_id'=>'synthetic-actor','operation'=>'identity.describe','schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'docker-key','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+30),'request_budget'=>2,'input'=>[],'service_grant'=>'synthetic-grant'];
    $result=$platform->invoke($env);check($result->kind==='success'&&$result->data['package_id']==='uniscript/demo','real_runtime_identity');
    $env['operation']='undefined.operation';check($platform->invoke($env)->kind==='unsupported_operation','uncertified_operation_blocked');
    $env['deadline']='2000-01-01T00:00:00Z';try{$platform->invoke($env);check(false,'expired_deadline');}catch(InvalidArgumentException){check(true,'expired_deadline');}
    $isolation=Process::run(['docker','exec','--env','HOST_SENTINEL_PATH='.$hostSentinel,$container,'php','-d','display_errors=0','-r','echo json_encode(["write"=>@file_put_contents("/package/probe","x"),"socket"=>file_exists("/var/run/docker.sock"),"host_source"=>file_exists(getenv("HOST_SENTINEL_PATH")),"egress"=>@fsockopen("1.1.1.1",443,$errno,$errstr,1)!==false]);']);
    $isolated=json_decode($isolation,true,32,JSON_THROW_ON_ERROR);check($isolated===['write'=>false,'socket'=>false,'host_source'=>false,'egress'=>false],'real_isolation');
    $contracts=Process::run(['docker','exec',$container,'php','-d','display_errors=0','-r','require "/opt/kit/bootstrap.php"; \UniScript\Integration\HostContract::assertProjection("fulfillments.read",["fulfillments"=>[]]);echo count(\UniScript\Integration\HostContract::timezones())>400 && class_exists(\UniScript\Integration\HostRequirements::class)?"ready":"missing";']);
    check($contracts==='ready','runtime_shares_complete_public_contracts');
}finally{if(isset($deployment['_container'])){Process::run(['docker','rm','-f',$deployment['_container']],30);}unlink($hostSentinel);}
// A success-shaped lifecycle must never certify always-rejecting/temporary stubs.
foreach(['validation_rejection','temporary_failure'] as $kind){
    $fixture=$state.'/stub-'.$kind;
    mkdir($fixture.'/src',0700,true);mkdir($fixture.'/schemas',0700);
    foreach(['integration.json','composer.json','composer.lock'] as $file){copy($root.'/examples/demo/'.$file,$fixture.'/'.$file);}
    foreach(glob($root.'/examples/demo/schemas/*.json') as $schema){copy($schema,$fixture.'/schemas/'.basename($schema));}
    $source="<?php\n\$handlers=[]; foreach(json_decode(file_get_contents(__DIR__.'/../integration.json'),true)['operations'] as \$operation){\$handlers[\$operation['key']]=static fn(\$inv,\$svc)=>new \\UniScript\\Integration\\Result('".$kind."','stub.unavailable');} return \$handlers;";
    file_put_contents($fixture.'/src/runtime.php',$source);
    $stubZip=$state.'/stub-'.$kind.'.zip';Process::run([PHP_BINARY,$root.'/bin/package.php',$fixture,$stubZip]);
    $stub=$platform->enqueue('builds',['execution_uuid'=>'stub-'.bin2hex(random_bytes(8)),'source_sha256'=>hash_file('sha256',$stubZip),'package_id'=>'uniscript/demo','package_version'=>json_decode(file_get_contents($root.'/examples/demo/integration.json'),true)['version']],$stubZip);
    $platform->work();$stub=$platform->read('builds',$stub['id']);successful($stub,'succeeded');
    $stubCert=$platform->enqueue('certifications',['execution_uuid'=>$stub['execution_uuid'],'build_id'=>$stub['id'],'source_sha256'=>$stub['source_sha256'],'digests'=>$stub['digests']]);
    $platform->work();$stubCert=$platform->read('certifications',$stubCert['id']);successful($stubCert,'failed');check(str_contains($stubCert['_diagnostic'],'conformance_unexpected_result'),'stub_cannot_certify');
}

// Worker-loss and deadline regressions prove that child work cannot survive the client.
$timeoutEnv=$env;$timeoutEnv['operation']='health.probe';$timeoutEnv['deadline']=gmdate('Y-m-d\TH:i:s\Z',time()+2);
$invocationName='uniscript-invocation-'.bin2hex(random_bytes(16));
$command=array_merge(['docker','run','--rm','--name',$invocationName,'-i'],UniScript\Integration\RuntimeProcess::flags(),['--entrypoint','php','sha256:'.$build['digests']['executable_image_sha256'],'-r','file_put_contents("/tmp/leaked-invocation-state","sensitive"); sleep(30);']);
$lost=UniScript\Integration\RuntimeProcess::invoke($command,$timeoutEnv,static fn(array $request):array=>[]);
check($lost->kind==='unknown_remote_outcome','timeout_is_unknown');
check(Process::run(['docker','ps','-a','-q','--filter','name=^/'.$invocationName.'$'])==='','timed_out_container_removed');
$timeoutEnv['deadline']=gmdate('Y-m-d\TH:i:s\Z',time()+10);
$invocationName='uniscript-invocation-'.bin2hex(random_bytes(16));
$command=array_merge(['docker','run','--rm','--name',$invocationName,'-i'],UniScript\Integration\RuntimeProcess::flags(),['--entrypoint','php','sha256:'.$build['digests']['executable_image_sha256'],'-r','echo "malformed frame\n"; fflush(STDOUT); file_put_contents("/tmp/leaked-invocation-state","sensitive"); sleep(30);']);
$lost=UniScript\Integration\RuntimeProcess::invoke($command,$timeoutEnv,static fn(array $request):array=>[]);
check($lost->kind==='unknown_remote_outcome','malformed_worker_is_unknown');check(Process::run(['docker','ps','-a','-q','--filter','name=^/'.$invocationName.'$'])==='','malformed_worker_container_removed');
$clean=Process::run(array_merge(['docker','run','--rm'],UniScript\Integration\RuntimeProcess::flags(),['--entrypoint','php','sha256:'.$build['digests']['executable_image_sha256'],'-r','echo file_exists("/tmp/leaked-invocation-state")?"leaked":"clean";']));
check($clean==='clean','subsequent_invocation_has_clean_tmp');
echo json_encode(['result'=>'passed','assertions'=>$assertions,'execution_uuid'=>$input['execution_uuid'],'digests'=>$build['digests']],JSON_THROW_ON_ERROR)."\n";
