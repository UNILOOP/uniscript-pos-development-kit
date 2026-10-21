<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Trusted platform tools. These files are never mounted into package containers. */
final class Platform
{
    public function __construct(public readonly string $state)
    {
        if (!is_dir($state)) { mkdir($state,0700,true); }
        foreach(['builds','certifications','deployments','sources','work'] as $dir){if(!is_dir($state.'/'.$dir)){mkdir($state.'/'.$dir,0700);}}
    }

    public function read(string $kind,string $id): array
    {
        if(!in_array($kind,['builds','certifications','deployments'],true)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)){throw new RuntimeException('not_found');}
        $path=$this->state.'/'.$kind.'/'.$id.'.json';if(!is_file($path)){throw new RuntimeException('not_found');}
        return json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    }

    public function save(string $kind,array $record): void
    {
        $path=$this->state.'/'.$kind.'/'.$record['id'].'.json';$temporary=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary,json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);chmod($temporary,0600);rename($temporary,$path);
    }

    public function enqueue(string $kind,array $input,?string $archive=null): array
    {
        foreach(($kind==='deployments'?['deployment_uuid','execution_uuid','certification_id','environment','digests']:($kind==='certifications'?['execution_uuid','build_id','source_sha256','digests']:['execution_uuid','source_sha256','package_id','package_version'])) as $key){if(!isset($input[$key])){throw new RuntimeException('missing_'.$key);}}
        if(!is_string($input['execution_uuid'])||!preg_match('/\A[a-zA-Z0-9-]{8,100}\z/D',$input['execution_uuid'])){throw new RuntimeException('invalid_execution_uuid');}
        if($kind!=='deployments'&&(!is_string($input['source_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$input['source_sha256']))){throw new RuntimeException('invalid_source_hash');}
        if($kind==='deployments'&&!in_array($input['environment'],['local','sandbox','dev'],true)){throw new RuntimeException('environment_not_enabled');}
        if($kind==='builds') {
            if($archive===null||filesize($archive)>20971520||!hash_equals($input['source_sha256'],hash_file('sha256',$archive))){throw new RuntimeException('source_integrity_failed');}
            if(!is_string($input['package_id'])||!is_string($input['package_version'])){throw new RuntimeException('invalid_package_identity');}
        }
        // A locked local ledger makes request retries idempotent, including concurrent HTTP workers.
        $ledger=fopen($this->state.'/enqueue.lock','c+');flock($ledger,LOCK_EX);
        try {
            $id=substr(hash('sha256',$kind.':'.($input['deployment_uuid']??$input['execution_uuid'])),0,32);
            $fingerprint=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
            if(is_file($this->state.'/'.$kind.'/'.$id.'.json')){$old=$this->read($kind,$id);if(!hash_equals($old['_fingerprint'],$fingerprint)){throw new RuntimeException('idempotency_conflict');}return $old;}
            if($archive!==null){if(!copy($archive,$this->state.'/sources/'.$id.'.zip')){throw new RuntimeException('source_storage_failed');}chmod($this->state.'/sources/'.$id.'.zip',0600);}
            $record=['id'=>$id,'status'=>'queued','execution_uuid'=>$input['execution_uuid'],'source_sha256'=>$input['source_sha256']??null,'digests'=>$input['digests']??null,'_input'=>$input,'_fingerprint'=>$fingerprint];
            if($kind==='deployments'){$record['deployment_uuid']=$input['deployment_uuid'];$record['environment']=$input['environment'];}
            $this->save($kind,$record);return $record;
        } finally{flock($ledger,LOCK_UN);fclose($ledger);}
    }

    public static function publicRecord(array $record): array
    {
        return array_filter($record,static fn(string $key):bool=>!str_starts_with($key,'_'),ARRAY_FILTER_USE_KEY);
    }

    public function work(): int
    {
        $processed=0;
        foreach(['builds','certifications','deployments'] as $kind){
            foreach(glob($this->state.'/'.$kind.'/*.json') as $file){
                $lease=fopen($file.'.lock','c+');
                if(!flock($lease,LOCK_EX|LOCK_NB)){fclose($lease);continue;}
                try{
                    $record=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
                    if(!in_array($record['status'],['queued','running'],true)){continue;}
                    $record['status']='running';$this->save($kind,$record);
                    try{$record=match($kind){'builds'=>$this->build($record),'certifications'=>$this->certify($record),'deployments'=>$this->deploy($record)};}
                    catch(\Throwable $exception){$record['status']='failed';$record['reason_code']='platform.operation_failed';$record['_diagnostic']=$exception->getMessage();}
                    $this->save($kind,$record);$processed++;
                }finally{flock($lease,LOCK_UN);fclose($lease);}
            }
        }
        return $processed;
    }

    private function build(array $record): array
    {
        $root=dirname(__DIR__);$job=$this->state.'/work/'.$record['id'].'-'.bin2hex(random_bytes(4));mkdir($job,0700);
        $manifest=Archive::unpack($this->state.'/sources/'.$record['id'].'.zip',$job.'/package',$record['source_sha256']);
        if($manifest['package_id']!==$record['_input']['package_id']||$manifest['version']!==$record['_input']['package_version']){throw new RuntimeException('source_identity_mismatch');}
        $base=getenv('PACKAGE_BASE_IMAGE')?:'php:8.4-cli-alpine';
        Process::run(['docker','image','inspect',$base],30);
        $baseInfo=json_decode(Process::run(['docker','image','inspect',$base],30),true,32,JSON_THROW_ON_ERROR)[0];
        $baseId=$baseInfo['Id'];
        $baseReference=$baseInfo['RepoDigests'][0]??throw new RuntimeException('base_repository_digest_required');
        if(!preg_match('/\Asha256:[a-f0-9]{64}\z/D',$baseId)){throw new RuntimeException('base_image_not_content_addressed');}
        mkdir($job.'/src');mkdir($job.'/bin');
        // Only the SDK and runtime supervisor enter the image, never platform keys or controller source.
        $runtimeFiles=['Invocation.php','Result.php','Services.php','ScopedServices.php','Runtime.php','Manifest.php','SchemaValidator.php','JsonValue.php','JsonDocument.php','ConfigurationSchema.php','ResourceSchema.php','StaticUi.php'];
        foreach($runtimeFiles as $file){copy($root.'/src/'.$file,$job.'/src/'.$file);}
        foreach(['invoke.php','runtime-server.php'] as $file){copy($root.'/bin/'.$file,$job.'/bin/'.$file);}
        copy($root.'/bootstrap.php',$job.'/bootstrap.php');copy($root.'/docker/Dockerfile',$job.'/Dockerfile');
        file_put_contents($job.'/.dockerignore',"*\n!Dockerfile\n!bootstrap.php\n!src/**\n!bin/**\n!package/**\n");
        Process::run(['docker','build','--network','none','--build-arg','BASE_IMAGE='.$baseReference,'--label','org.uniscript.source='.$record['source_sha256'],'--label','org.uniscript.execution='.$record['execution_uuid'],'--iidfile',$job.'/image.id',$job],180);
        $image=trim(file_get_contents($job.'/image.id'));
        if(!preg_match('/\Asha256:[a-f0-9]{64}\z/D',$image)){throw new RuntimeException('invalid_image_identity');}
        $record['digests']=[
            'lockfile_sha256'=>hash_file('sha256',$job.'/package/composer.lock'),
            'dependencies_sha256'=>hash('sha256','[]'),
            'sdk_sha256'=>self::treeHash($job.'/src'),
            'supervisor_sha256'=>self::treeHash($job.'/bin'),
            'base_image_sha256'=>substr($baseId,7),
            'executable_image_sha256'=>substr($image,7),
            'ui_sha256'=>StaticUi::inspect($job.'/package',$manifest)['sha256'],
        ];
        $record['_manifest']=$manifest;$record['_work']=$job;$record['status']='succeeded';return $record;
    }

    private function certify(array $record): array
    {
        $input=$record['_input'];$build=$this->read('builds',$input['build_id']);
        if($build['status']!=='succeeded'||$build['execution_uuid']!==$record['execution_uuid']||!hash_equals($build['source_sha256'],$record['source_sha256'])||!self::sameDigests($build['digests'],$record['digests'])){throw new RuntimeException('certification_binding_mismatch');}
        // Reinspect source independently from the builder, and inspect the actual immutable image.
        $inspection=$this->state.'/work/cert-'.$record['id'].'-'.bin2hex(random_bytes(4));
        $manifest=Archive::unpack($this->state.'/sources/'.$build['id'].'.zip',$inspection,$record['source_sha256']);
        if(!hash_equals(StaticUi::inspect($inspection,$manifest)['sha256'],$record['digests']['ui_sha256'])){throw new RuntimeException('ui_artifact_mismatch');}
        $image='sha256:'.$record['digests']['executable_image_sha256'];
        $actual=json_decode(Process::run(['docker','image','inspect',$image],30),true,32,JSON_THROW_ON_ERROR)[0];
        if($actual['Id']!==$image||($actual['Config']['Labels']['org.uniscript.source']??null)!==$record['source_sha256']||($actual['Config']['Labels']['org.uniscript.execution']??null)!==$record['execution_uuid']){throw new RuntimeException('image_provenance_mismatch');}
        // Recompute platform ingredients, not a package-provided passing assertion.
        if(hash_file('sha256',$inspection.'/composer.lock')!==$record['digests']['lockfile_sha256']||self::treeHash($build['_work'].'/src')!==$record['digests']['sdk_sha256']||self::treeHash($build['_work'].'/bin')!==$record['digests']['supervisor_sha256']){throw new RuntimeException('artifact_ingredient_mismatch');}
        $host=new SyntheticHost();$tested=[];$observed=[];
        foreach($manifest['operations'] as $operation){
            foreach(ConformanceProfile::scenarios($operation['key'],$manifest['package_id'],$manifest['manifest_version'],$manifest['version']) as $scenario){
                $envelope=$this->syntheticEnvelope($record,$manifest,$operation['key']);
                $envelope['input']=$scenario['input'];
                if ($operation['key'] === 'inbound.decode' && is_array($scenario['input']['events'] ?? null)) { $envelope['request_budget'] = max(1, min(100, count($scenario['input']['events']))); }
                $envelope['service_grant']=$host->grant($envelope,['storage.read','storage.write','resources.read','commands.propose','http.request']);
                $command=array_merge(['docker','run','--rm','-i'],RuntimeProcess::flags(),['--env','PACKAGE_ROOT=/package','--env','EXECUTION_REVISION='.$record['execution_uuid'],'--env','SERVICE_TRANSPORT=stdio','--entrypoint','timeout',$image,'-s','KILL','30','php','-d','display_errors=0','-d','log_errors=0','/opt/kit/bin/invoke.php']);
                $scenarioCalls=[];$scenarioRequests=[];
                $result=RuntimeProcess::invoke($command,$envelope,static function(array $request)use(&$scenarioCalls,&$scenarioRequests,$host,&$observed,$scenario,$manifest,$inspection):array{if(($request['service']??null)==='storage'&&($request['action']??null)==='write'&&($manifest['manifest_version']??null)===2){$resource=$request['arguments']['key']??'';$path=$manifest['resource_schemas'][$resource]??throw new RuntimeException('resource_not_declared');$definition=JsonDocument::decode(file_get_contents($inspection.'/'.$path));ResourceSchema::validateValues($definition,$request['arguments']['value']??[]);}$response=ConformanceProfile::serviceResponse($manifest['package_id'],$scenario,$request,$host);$observed[]=$request['service'].'.'.$request['action'];$scenarioCalls[]=$request['service'].'.'.$request['action'];$scenarioRequests[]=$request;return $response;});
                ConformanceProfile::verify($operation['key'],$scenario,$result,$manifest['package_id'],$scenarioCalls,$scenarioRequests);
            }
            $tested[]=$operation['key'];
        }
        if($tested===[]){throw new RuntimeException('no_certified_operations');}
        $record['certification_summary']=['operations'=>$tested,'host_services'=>array_values(array_unique($observed))];
        $record['status']='passed';$record['_package_id']=$manifest['package_id'];$record['_manifest_version']=$manifest['manifest_version'];return $record;
    }

    private function deploy(array $record): array
    {
        $input=$record['_input'];$cert=$this->read('certifications',$input['certification_id']);
        if($cert['status']!=='passed'||$cert['execution_uuid']!==$record['execution_uuid']||!self::sameDigests($cert['digests'],$record['digests'])){throw new RuntimeException('deployment_binding_mismatch');}
        $image='sha256:'.$record['digests']['executable_image_sha256'];$name='uniscript-package-'.$record['id'];
        $old=proc_open(['docker','rm','-f',$name],[1=>['pipe','w'],2=>['pipe','w']],$pipes);foreach($pipes as $pipe){stream_get_contents($pipe);fclose($pipe);}proc_close($old);
        Process::run(array_merge(['docker','run','-d','--name',$name],RuntimeProcess::flags(),['--env','EXECUTION_REVISION='.$record['execution_uuid'],$image]),30);
        $running=Process::run(['docker','inspect','--format','{{.State.Running}}',$name],30);
        if($running!=='true'){throw new RuntimeException('runtime_not_ready');}
        $readinessOperation=ConformanceProfile::readinessOperation($cert['_package_id']);
        $health=$this->syntheticEnvelope($record,['package_id'=>$cert['_package_id']],$readinessOperation);
        $health['environment']=$record['environment'];
        $health['input']=ConformanceProfile::scenarios($readinessOperation,$cert['_package_id'],$cert['_manifest_version']??1)[0]['input'];
        $health['service_grant']='readiness-no-services';$health['request_budget']=0;
        $probe=['docker','exec','-i','--env','PACKAGE_ROOT=/package','--env','EXECUTION_REVISION='.$record['execution_uuid'],'--env','SERVICE_TRANSPORT=stdio',$name,'timeout','-s','KILL','10','php','-d','display_errors=0','/opt/kit/bin/invoke.php'];
        $result=RuntimeProcess::invoke($probe,$health,static fn(array $request):array=>throw new RuntimeException('readiness_services_denied'));
        if($result->kind!=='success'){throw new RuntimeException('runtime_health_not_ready');}
        $record['status']='ready';$record['source_sha256']=$cert['source_sha256'];$record['executable_image_sha256']=$record['digests']['executable_image_sha256'];
        $record['_container']=$name;$record['_package_id']=$cert['_package_id'];$record['_operations']=$cert['certification_summary']['operations'];return $record;
    }

    public function invoke(array $envelope): Result
    {
        Invocation::fromArray($envelope);if($envelope['environment']==='production'||($envelope['deployment_environment']??$envelope['environment'])==='production'){throw new RuntimeException('production_not_enabled');}$deployment=null;
        foreach(glob($this->state.'/deployments/*.json') as $file){$candidate=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);if($candidate['status']==='ready'&&$candidate['execution_uuid']===$envelope['execution_revision']&&$candidate['_package_id']===$envelope['package_id']&&$candidate['environment']===($envelope['deployment_environment']??$envelope['environment'])){$deployment=$candidate;break;}}
        if($deployment===null){throw new RuntimeException('deployment_not_ready');}
        if(!in_array($envelope['operation'],$deployment['_operations'],true)){return new Result('unsupported_operation','operation.not_certified');}
        $image=Process::run(['docker','inspect','--format','{{.Image}}',$deployment['_container']],10);
        if(!hash_equals('sha256:'.$deployment['executable_image_sha256'],$image)){throw new RuntimeException('deployment_image_drift');}
        $invocationContainer='uniscript-invocation-'.bin2hex(random_bytes(16));
        $command=array_merge(['docker','run','--rm','--name',$invocationContainer,'-i'],RuntimeProcess::flags(),['--env','PACKAGE_ROOT=/package','--env','EXECUTION_REVISION='.$deployment['execution_uuid'],'--env','SERVICE_TRANSPORT=stdio','--entrypoint','timeout',$image,'-s','KILL',(string)max(1,min(30,strtotime($envelope['deadline'])-time())),'php','-d','display_errors=0','-d','log_errors=0','/opt/kit/bin/invoke.php']);
        return RuntimeProcess::invoke($command,$envelope,static function(array $request)use($envelope):array{
            $endpoint=getenv('HOST_SERVICES_URL')?:throw new RuntimeException('host_services_unconfigured');
            $parts=parse_url($endpoint);if(!is_array($parts)||!in_array($parts['scheme']??'',['http','https'],true)||isset($parts['user'])||isset($parts['pass'])){throw new RuntimeException('host_services_endpoint_invalid');}
            $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>json_encode($request,JSON_THROW_ON_ERROR),'timeout'=>5,'follow_location'=>0,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $limit=PayloadLimits::forPackage($request['package_id']??$envelope['package_id']??'');$response=file_get_contents($endpoint,false,$ctx,0,$limit+1);if($response===false||strlen($response)>$limit){throw new RuntimeException('host_services_transport_failed');}
            $decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);
            $headers=http_get_last_response_headers();
            if(!isset($headers[0])||!preg_match('~\s2\d\d\s~',$headers[0])){throw new RuntimeException('host_services_denied');}
            return $decoded;
        });
    }

    private function syntheticEnvelope(array $record,array $manifest,string $operation):array
    {
        return ['protocol_version'=>'1','invocation_id'=>bin2hex(random_bytes(16)),'command_id'=>'synthetic-command','attempt_id'=>'synthetic-attempt','correlation_id'=>'synthetic-correlation','package_id'=>$manifest['package_id'],'execution_revision'=>$record['execution_uuid'],'connection_id'=>'synthetic-connection','organization_id'=>'synthetic-organization','location_id'=>'synthetic-location','environment'=>'local','actor_id'=>'synthetic-actor','operation'=>$operation,'schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'synthetic-key','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+30),'request_budget'=>10,'input'=>['order_id'=>'synthetic-order','authenticated_receipt'=>true,'receipt_handle'=>'synthetic-receipt','event_id'=>'synthetic-event'],'service_grant'=>'pending'];
    }

    private static function sameDigests(mixed $left,mixed $right):bool
    {
        $keys=['lockfile_sha256','dependencies_sha256','sdk_sha256','supervisor_sha256','base_image_sha256','executable_image_sha256','ui_sha256'];
        foreach([$left,$right] as $value){if(!is_array($value)||count($value)!==count($keys)||array_diff($keys,array_keys($value))!==[]){return false;}foreach($value as $digest){if(!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)){return false;}}}
        ksort($left);ksort($right);return $left===$right;
    }

    public static function treeHash(string $root):string
    {
        $files=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file){if($file->isFile()){$files[substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname());}}ksort($files,SORT_STRING);return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR));
    }
}
