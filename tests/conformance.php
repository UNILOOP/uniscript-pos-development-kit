<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Invocation;
use UniScript\Integration\Result;
use UniScript\Integration\Runtime;
use UniScript\Integration\ScopedServices;
use UniScript\Integration\SyntheticHost;

$assertions = 0;
function check(bool $condition, string $reason): void { global $assertions; $assertions++; if (!$condition) { throw new RuntimeException('assertion_failed:'.$reason); } }
function denied(callable $call, string $reason): void { try { $call(); } catch (Throwable) { check(true, $reason); return; } check(false, $reason); }
function envelope(string $package, string $operation, string $key = 'order-a', array $input = [], array $overrides = []): array {
    return array_replace(['protocol_version'=>'1','invocation_id'=>bin2hex(random_bytes(8)),'command_id'=>'command-a','attempt_id'=>'attempt-a','correlation_id'=>'correlation-a','package_id'=>'uniscript/'.$package,'execution_revision'=>'test-revision','connection_id'=>'connection-a','organization_id'=>'organization-a','location_id'=>'location-a','environment'=>'local','actor_id'=>'synthetic-actor','operation'=>$operation,'schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>$key,'deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+60),'request_budget'=>10,'input'=>$input,'service_grant'=>'pending'], $overrides);
}
function invoke(SyntheticHost $host, string $package, string $operation, string $key='order-a', array $input=[], array $overrides=[]): Result {
    $env=envelope($package,$operation,$key,$input,$overrides);
    $env['service_grant']=$host->grant($env,['http.request','storage.read','storage.write','resources.read','commands.propose']);
    $inv=Invocation::fromArray($env);
    return (new Runtime(dirname(__DIR__).'/examples/'.$package,'test-revision'))->invoke($inv,new ScopedServices($inv,$host->transport(...)));
}

$host=new SyntheticHost();
foreach (['starter','demo'] as $package) {
    check(invoke($host,$package,'identity.describe')->data['package_id']==='uniscript/'.$package,'identity');
    check(invoke($host,$package,'health.probe',input:$package==='demo'?['config'=>['vendor_id'=>'10001','location_id'=>'10002','api_network_id'=>'10003']]:[])->kind==='success','health');
    check(invoke($host,$package,'orders.validate')->kind==='validation_rejection','validation');
    check(invoke($host,$package,'undefined.operation')->kind==='unsupported_operation','unsupported');
}
$first=invoke($host,'demo','orders.submit','same-key',['order_id'=>'synthetic-order']);
$second=invoke($host,'demo','orders.submit','same-key',['order_id'=>'synthetic-order']);
check($first->kind==='remote_acceptance' && $first->data===$second->data,'idempotency');
check($host->providerSubmissions===1,'single_submission');
$unknown=invoke($host,'demo','orders.submit','lost-key',['order_id'=>'synthetic-order','scenario'=>'accepted_then_lost']);
check($unknown->kind==='unknown_remote_outcome' && $unknown->recovery['resubmit_allowed']===false,'unknown_outcome');
check(invoke($host,'demo','orders.reconcile','lost-key')->kind==='remote_acceptance','reconciliation');
check($host->providerSubmissions===2,'reconcile_does_not_resubmit');
check(invoke($host,'demo','inbound.decode','callback',[])->kind==='validation_rejection','unauthenticated_callback');
check(invoke($host,'demo','inbound.decode','callback',['authenticated_receipt'=>true,'receipt_handle'=>'receipt-a','event_id'=>'event-a'])->data['applied']===false,'callback_is_proposal');
check(invoke($host,'demo','catalog.preview',input:['records'=>[['source_key'=>'synthetic-product-a','display_name'=>'Synthetic saline']]])->data['applied']===false,'catalog_is_proposal');
check(invoke($host,'demo','reference.refresh')->data['revision']===1,'storage_initial_revision');
denied(fn()=>invoke($host,'demo','reference.refresh'),'stale_revision');
check(invoke($host,'demo','reference.refresh','ref',[],['connection_id'=>'connection-b'])->data['revision']===1,'connection_isolation');
$env=envelope('demo','health.probe');
$env['service_grant']=$host->grant($env,['storage.read']);
$inv=Invocation::fromArray($env); $svc=new ScopedServices($inv,$host->transport(...));
denied(fn()=>$svc->request('storage','read',['connection_id'=>'other','key'=>'x']),'scope_override');
denied(fn()=>$svc->request('http','request',[]),'ungranted_service');
$stolen=$env; $stolen['invocation_id']='other';
$stolenInv=Invocation::fromArray($stolen);
denied(fn()=>(new ScopedServices($stolenInv,$host->transport(...)))->request('storage','read',['key'=>'x']),'grant_invocation_binding');
denied(fn()=>Invocation::fromArray(envelope('demo','health.probe',overrides:['deadline'=>'2000-01-01T00:00:00Z'])),'expired_envelope');
denied(fn()=>Invocation::fromArray(envelope('demo','health.probe',overrides:['request_budget'=>101])),'budget');
denied(fn()=>new Result('bogus','bad'),'result_discriminant');
$runtime=new Runtime(dirname(__DIR__).'/examples/demo','other-revision');
denied(fn()=>$runtime->invoke($inv,$svc),'execution_pinning');
$before=$inv->envelope; $copy=$inv->input(); $copy['other']='change'; check($before===$inv->envelope,'immutable_snapshot');
if (class_exists(ZipArchive::class)) {
    $a=tempnam(sys_get_temp_dir(),'kit-a'); $b=tempnam(sys_get_temp_dir(),'kit-b');
    try {
        foreach([$a,$b] as $file) {
            $command=[PHP_BINARY,dirname(__DIR__).'/bin/package.php',dirname(__DIR__).'/examples/demo',$file];
            $proc=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes); stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'packaging:'.$error);
        }
        check(hash_file('sha256',$a)===hash_file('sha256',$b),'deterministic_archives');
    } finally { unlink($a);unlink($b); }
}
echo json_encode(['result'=>'passed','assertions'=>$assertions,'php'=>PHP_VERSION],JSON_THROW_ON_ERROR)."\n";
