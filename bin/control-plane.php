<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Platform;

header('Content-Type: application/json');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$kind=null;$id=null;
if($path==='/v1/invocations'){$role='RUNTIME_TOKEN';}
elseif(preg_match('~\A/v1/(builds|certifications|deployments)(?:/([a-f0-9]{32}))?\z~D',$path,$match)){$kind=$match[1];$id=$match[2]??null;$role=match($kind){'builds'=>'BUILDER_TOKEN','certifications'=>'CERTIFIER_TOKEN','deployments'=>'DEPLOYER_TOKEN'};}
else{http_response_code(404);echo '{"error":"not_found"}';return;}
$token=getenv($role)?:'';
if(strlen($token)<32||!hash_equals('Bearer '.$token,$_SERVER['HTTP_AUTHORIZATION']??'')){http_response_code(401);echo '{"error":"unauthorized"}';return;}
try{
    $platform=new Platform(getenv('PLATFORM_STATE')?:dirname(__DIR__).'/var/platform');
    if($_SERVER['REQUEST_METHOD']==='GET'&&$id!==null){$result=Platform::publicRecord($platform->read($kind,$id));}
    elseif($_SERVER['REQUEST_METHOD']==='POST'&&$id===null){
        if($kind==='builds'){
            if(!isset($_FILES['archive'])||$_FILES['archive']['error']!==UPLOAD_ERR_OK||!is_uploaded_file($_FILES['archive']['tmp_name'])){throw new RuntimeException('archive_required');}
            $result=Platform::publicRecord($platform->enqueue($kind,$_POST,$_FILES['archive']['tmp_name']));http_response_code(202);
        }else{
            $readLimit=$kind===null?12582912:1048576;$json=file_get_contents('php://input',false,null,0,$readLimit+1);if(strlen($json)>$readLimit){throw new RuntimeException('request_limit');}
            $input=json_decode($json,true,32,JSON_THROW_ON_ERROR);if(!is_array($input)){throw new RuntimeException('invalid_request');}
            $result=$kind===null?$platform->invoke($input):Platform::publicRecord($platform->enqueue($kind,$input));
            if($kind!==null){http_response_code(202);}
        }
    }else{http_response_code(405);$result=['error'=>'method_not_allowed'];}
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable $exception){http_response_code($exception->getMessage()==='not_found'?404:422);echo json_encode(['error'=>'request_rejected','reason_code'=>preg_match('/\A[a-z_]+\z/D',$exception->getMessage())?$exception->getMessage():'invalid_request'],JSON_THROW_ON_ERROR);}
