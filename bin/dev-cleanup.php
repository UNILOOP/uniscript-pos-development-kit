<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$state=getenv('PLATFORM_STATE')?:'/state/platform';$count=0;
foreach(glob($state.'/deployments/*.json') as $path){$record=UniScript\Integration\JsonDocument::decode(file_get_contents($path),true);$name=$record['_container']??'uniscript-package-'.($record['id']??'');if(!preg_match('/\Auniscript-package-[a-f0-9]{32}\z/D',$name)){continue;}try{UniScript\Integration\Process::run(['docker','rm','-f',$name],15);$count++;}catch(Throwable){}}
echo json_encode(['removed_local_deployment_containers'=>$count],JSON_THROW_ON_ERROR)."\n";
