<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConformanceProfile;
use UniScript\Integration\Invocation;
use UniScript\Integration\Manifest;
use UniScript\Integration\PayloadLimits;
use UniScript\Integration\Result;
use UniScript\Integration\Runtime;
use UniScript\Integration\ScopedServices;
use UniScript\Integration\SyntheticHost;

$root=$argv[1]??getenv('UNISCRIPT_LIFEFILE_PACKAGE');
if(!is_string($root)||$root===''){throw new InvalidArgumentException('usage: php tests/lifefile-api-v2.php PACKAGE_SOURCE_DIRECTORY (or set UNISCRIPT_LIFEFILE_PACKAGE)');}
if(!is_file($root.'/integration.json')){throw new InvalidArgumentException('lifefile_package_source_missing');}
$manifest=Manifest::read($root);$assertions=0;$scenariosTested=0;
function check(bool $condition,string $message):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException($message);}}
function envelope(string $operation,array $input):array{return ['protocol_version'=>'1','invocation_id'=>'synthetic-invocation','command_id'=>'command','attempt_id'=>'attempt','correlation_id'=>'correlation','package_id'=>'uniscript/lifefile-v2','execution_revision'=>'revision','connection_id'=>'connection','organization_id'=>'organization','location_id'=>'location','environment'=>'local','actor_id'=>'actor','operation'=>$operation,'schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'synthetic-key','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+60),'request_budget'=>20,'input'=>$input,'service_grant'=>'pending'];}
$host=new SyntheticHost;
foreach($manifest['operations'] as $operation){foreach(ConformanceProfile::scenarios($operation['key'],$manifest['package_id'],2,'2.0.0') as $scenario){$scope=envelope($operation['key'],$scenario['input']);$scope['service_grant']=$host->grant($scope,['http.request','commands.propose']);$calls=[];$requests=[];$services=new ScopedServices(Invocation::fromArray($scope),static function(array $request)use($host,$scenario,&$calls,&$requests):array{$calls[]=$request['service'].'.'.$request['action'];$requests[]=$request;return ConformanceProfile::serviceResponse('uniscript/lifefile-v2',$scenario,$request,$host);});$result=(new Runtime($root,'revision'))->invoke(Invocation::fromArray($scope),$services);ConformanceProfile::verify($operation['key'],$scenario,$result,'uniscript/lifefile-v2',$calls,$requests);$scenariosTested++;}}
check($scenariosTested>=55,'trusted_all_operation_scenarios');
check(ConformanceProfile::readinessOperation('uniscript/lifefile-v2')==='identity.describe','no_vendor_probe_during_process_readiness');
check(ConformanceProfile::readinessOperation('uniscript/lifefile')==='health.probe','legacy_readiness_preserved');
check(PayloadLimits::forPackage('uniscript/lifefile-v2')===12582912,'v2_12mib_frames');
check(PayloadLimits::forPackage('uniscript/lifefile')===1048576,'legacy_1mib_frames');
$large=str_repeat('x',1100000);$scope=envelope('lifefile.api.execute',['payload'=>$large]);Invocation::fromArray($scope);$assertions++;
$scope['package_id']='uniscript/lifefile';try{Invocation::fromArray($scope);throw new RuntimeException('legacy_input_limit_bypassed');}catch(InvalidArgumentException){$assertions++;}
try{new Result('success','synthetic.result',['payload'=>$large]);throw new RuntimeException('default_result_limit_bypassed');}catch(InvalidArgumentException){$assertions++;}
$largeResult=new Result('success','synthetic.result',['payload'=>$large],maxBytes:12582912);Result::fromArray($largeResult->jsonSerialize(),12582912);$assertions++;
try{Result::fromArray($largeResult->jsonSerialize());throw new RuntimeException('legacy_result_limit_bypassed');}catch(InvalidArgumentException){$assertions++;}
$pdf=base64_encode('%PDF-1.7'.str_repeat(' ',8388608-8));
$command=[PHP_BINARY,'-d','memory_limit=256M','-d','display_errors=0',dirname(__DIR__).'/bin/invoke.php'];
putenv('PACKAGE_ROOT='.$root);putenv('EXECUTION_REVISION=revision');putenv('SERVICE_TRANSPORT=stdio');
$scope=envelope('lifefile.api.execute',['operation_id'=>'appendOrderDocumentPages','parameters'=>['external_reference_handle'=>'synthetic-reference'],'body'=>['pdfBase64'=>$pdf]]);$scope['service_grant']='synthetic-stdio-grant';
$calls=0;$result=UniScript\Integration\RuntimeProcess::invoke($command,$scope,static function(array $request)use(&$calls,$pdf):array{$calls++;check(($request['arguments']['body']['pdfBase64']??null)===$pdf,'8mib_pdf_write_exact_frame');return ['status'=>'accepted','data'=>['type'=>'success']];});
check($result->kind==='success'&&$calls===1,'8mib_pdf_input_stdio_round_trip');
$scope=envelope('lifefile.api.execute',['operation_id'=>'getOrderDocument','parameters'=>['external_reference_handle'=>'synthetic-reference']]);$scope['service_grant']='synthetic-stdio-grant';
$result=UniScript\Integration\RuntimeProcess::invoke($command,$scope,static fn(array $request):array=>['status'=>'accepted','data'=>['type'=>'success','data'=>['pdfBase64'=>$pdf]]]);
check($result->kind==='success'&&($result->data['response']['data']['pdfBase64']??null)===$pdf,'8mib_pdf_output_stdio_round_trip');
$scope=envelope('identity.describe',[]);$scope['environment']='production';$scope['deployment_environment']='production';Invocation::fromArray($scope);$assertions++;
$scope['package_id']='uniscript/lifefile';Invocation::fromArray($scope);check(!ConformanceProfile::productionAllowed('uniscript/lifefile'),'legacy_structural_envelope_cannot_authorize_production');
$platform=new UniScript\Integration\Platform(sys_get_temp_dir().'/kit-v2-environments-'.bin2hex(random_bytes(6)));
$certificate=['id'=>str_repeat('a',32),'status'=>'passed','_package_id'=>'uniscript/lifefile-v2'];$platform->save('certifications',$certificate);
$deployment=['deployment_uuid'=>'synthetic-production','execution_uuid'=>'synthetic-execution','certification_id'=>$certificate['id'],'environment'=>'production','digests'=>[]];
check($platform->enqueue('deployments',$deployment)['status']==='queued','new_v2_production_requires_passed_certificate');
$certificate['_package_id']='uniscript/lifefile';$platform->save('certifications',$certificate);
try{$platform->enqueue('deployments',[...$deployment,'deployment_uuid'=>'legacy-production']);throw new RuntimeException('legacy_production_allowed');}catch(RuntimeException $error){check($error->getMessage()==='environment_not_enabled','legacy_production_remains_disabled');}
$certificate['_package_id']='uniscript/lifefile-v2';$certificate['status']='failed';$platform->save('certifications',$certificate);
try{$platform->enqueue('deployments',[...$deployment,'deployment_uuid'=>'uncertified-production']);throw new RuntimeException('uncertified_production_allowed');}catch(RuntimeException $error){check($error->getMessage()==='environment_not_enabled','uncertified_production_denied');}
echo json_encode(['result'=>'passed','assertions'=>$assertions,'scenarios'=>$scenariosTested],JSON_THROW_ON_ERROR)."\n";
