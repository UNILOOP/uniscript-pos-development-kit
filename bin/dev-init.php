<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Process;

// This trusted local initializer owns Docker access; package containers never receive it.
Process::run(['docker','pull','php:8.4-cli-alpine'],180);
if(is_file('/configs/operator/environment.json')){echo "Existing private local configuration retained.\n";exit(0);}
$tokens=[];foreach(['BUILDER_TOKEN','CERTIFIER_TOKEN','DEPLOYER_TOKEN','RUNTIME_TOKEN','BROKER_ADMIN_TOKEN','MOCK_POS_PASSWORD','CALLBACK_SIGNING_KEY','PAYLOAD_KEY_HEX','REPLAY_KEY_HEX'] as $key){$tokens[$key]=bin2hex(random_bytes(32));}
$tokens['MOCK_POS_USER']='synthetic-kit';$tokens['CALLBACK_ENDPOINT_ID']=bin2hex(random_bytes(16));
$configs=[
    'cp'=>['PLATFORM_STATE'=>'/state/platform','HOST_SERVICES_URL'=>'http://broker:8080/services',...array_intersect_key($tokens,array_flip(['BUILDER_TOKEN','CERTIFIER_TOKEN','DEPLOYER_TOKEN','RUNTIME_TOKEN']))],
    'broker'=>array_intersect_key($tokens,array_flip(['BROKER_ADMIN_TOKEN','MOCK_POS_USER','MOCK_POS_PASSWORD'])),
    'mock'=>['MOCK_POS_DATABASE'=>'/data/pos.sqlite',...array_intersect_key($tokens,array_flip(['MOCK_POS_USER','MOCK_POS_PASSWORD']))],
    'operator'=>['RUNTIME_URL'=>'http://control-plane:8080','BROKER_URL'=>'http://broker:8080','CALLBACK_URL'=>'http://callbacks:8080',...$tokens],
];
$scope=['package_id'=>'uniscript/demo','organization_id'=>'synthetic-organization','location_id'=>'synthetic-location','connection_id'=>'synthetic-connection','environment'=>'sandbox'];
$callback=['state'=>'/data/receipts','payload_key_hex'=>$tokens['PAYLOAD_KEY_HEX'],'replay_key_hex'=>$tokens['REPLAY_KEY_HEX'],'endpoints'=>[$tokens['CALLBACK_ENDPOINT_ID']=>[...$scope,'provider_account_handle'=>'synthetic-provider-account','execution_revision'=>'synthetic-original-decoder','signing_key'=>$tokens['CALLBACK_SIGNING_KEY'],'references'=>['synthetic-vendor-order'=>[[...$scope,'order_handle'=>'synthetic-order','attempt_handle'=>'synthetic-attempt','execution_revision'=>'synthetic-original-decoder']]]]]];
foreach($configs as $role=>$config){file_put_contents('/configs/'.$role.'/environment.json',json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));chmod('/configs/'.$role.'/environment.json',0600);chown('/configs/'.$role.'/environment.json',10001);}
file_put_contents('/configs/callback/configuration.json',json_encode($callback,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));chmod('/configs/callback/configuration.json',0600);chown('/configs/callback/configuration.json',10001);
foreach(['/data/broker','/data/mock','/data/callback','/artifacts'] as $path){chown($path,10001);chgrp($path,10001);chmod($path,0700);}
echo "Private synthetic local configuration initialized.\n";
