<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use UniScript\Integration\Invocation;
use UniScript\Integration\Services;

$handlers = require dirname(__DIR__).'/examples/demo/src/runtime.php';
$services = new class implements Services {
    public function request(string $service, string $action, array $arguments): array { throw new RuntimeException('catalog_must_have_no_service_grants'); }
};
$invoke = static function (array $input) use ($handlers, $services) {
    $env = ['protocol_version'=>'1','invocation_id'=>'synthetic-catalog','command_id'=>'command','attempt_id'=>'attempt','correlation_id'=>'correlation','package_id'=>'uniscript/demo','execution_revision'=>'execution','connection_id'=>'connection','organization_id'=>'organization','location_id'=>'location','environment'=>'local','actor_id'=>'actor','operation'=>'catalog.preview','schema_version'=>'1','routing_revision'=>'1','configuration_revision'=>'1','mapping_revision'=>'1','credential_revision'=>'1','idempotency_key'=>'preview','deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+30),'request_budget'=>0,'input'=>$input,'service_grant'=>'no-host-grant'];
    return $handlers['catalog.preview'](Invocation::fromArray($env), $services);
};
$assertions=0;
$input=['records'=>[['source_key'=>'vendor-a','display_name'=>'Synthetic saline','vendor_product'=>'7'],['source_key'=>'vendor-b','display_name'=>'Synthetic supply']]];
$result=$invoke($input);
if($result->kind!=='success'||$result->data!==['proposals'=>[['source_key'=>'vendor-a','display_name'=>'Synthetic saline','canonical_item_handle'=>null,'mapping_values'=>['vendor_product'=>7],'warnings'=>[]],['source_key'=>'vendor-b','display_name'=>'Synthetic supply','canonical_item_handle'=>null,'mapping_values'=>null,'warnings'=>[]]],'applied'=>false]){throw new RuntimeException('catalog_projection');}$assertions++;
foreach([[],['records'=>[]],['records'=>array_fill(0,1001,$input['records'][0])],['records'=>[['source_key'=>'','display_name'=>'Invalid']]],['records'=>[['source_key'=>'a','display_name'=>'Valid','vendor_product'=>false]]],['records'=>[['source_key'=>'a','display_name'=>'Valid','canonical_item_handle'=>'cross-tenant-id']]]] as $invalid){if($invoke($invalid)->kind!=='validation_rejection'){throw new RuntimeException('invalid_catalog_input_accepted');}$assertions++;}
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
