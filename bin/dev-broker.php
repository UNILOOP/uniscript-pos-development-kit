<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
header('Content-Type: application/json');
try{
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if($_SERVER['REQUEST_METHOD']==='GET'&&$path==='/health'){echo '{"status":"ready","synthetic":true}';return;}
    $body=file_get_contents('php://input',false,null,0,1048577);if(strlen($body)>1048576){throw new RuntimeException('request_limit');}$input=UniScript\Integration\JsonDocument::decode($body,true);
    $broker=new UniScript\Integration\LocalSyntheticBroker('/data/broker.sqlite');
    if($_SERVER['REQUEST_METHOD']==='POST'&&$path==='/grants'){$expected=getenv('BROKER_ADMIN_TOKEN')?:'';if(strlen($expected)<32||!hash_equals('Bearer '.$expected,$_SERVER['HTTP_AUTHORIZATION']??'')){throw new RuntimeException('unauthorized');}$broker->register($input['invocation']??[], $input['resources']??[]);$result=['status'=>'registered'];}
    elseif($_SERVER['REQUEST_METHOD']==='POST'&&$path==='/services'){$result=$broker->request($input);}
    else{http_response_code(404);$result=['error'=>'not_found'];}
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable){http_response_code(422);echo '{"error":"synthetic_broker_rejected"}';}
