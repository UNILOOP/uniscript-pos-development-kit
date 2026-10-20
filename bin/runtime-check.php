<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\HostRuntimeClient;
use UniScript\Integration\JsonDocument;

try{
    $options=getopt('',['expect:']);$expected=$options['expect']??throw new InvalidArgumentException('expected_kind_required');
    $url=getenv('PACKAGE_RUNTIME_URL')?:getenv('RUNTIME_URL')?:throw new InvalidArgumentException('runtime_url_required');
    $token=getenv('PACKAGE_RUNTIME_TOKEN')?:getenv('RUNTIME_TOKEN')?:throw new InvalidArgumentException('runtime_token_required');
    $input=stream_get_contents(STDIN,1048577);if(strlen($input)>1048576){throw new InvalidArgumentException('invocation_limit');}
    $envelope=JsonDocument::decode($input,true);if(!is_array($envelope)){throw new InvalidArgumentException('invocation_object_required');}
    $result=(new HostRuntimeClient($url,$token))->invoke($envelope,$expected);
    $summary=['kind'=>$result->kind,'reason_code'=>$result->reasonCode];
    if(is_string($result->data['external_reference_handle']??null)){$summary['external_reference_handle']=$result->data['external_reference_handle'];}
    echo json_encode($summary,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Runtime fixture check failed; inspect authorized host diagnostics.\n");exit(1);}
