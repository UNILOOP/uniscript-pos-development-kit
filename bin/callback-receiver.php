<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\JsonDocument;
use UniScript\Integration\SyntheticCallbackReceiver;
use UniScript\Integration\SyntheticReplayAuthority;

header('Content-Type: application/json');
try{
    $file=getenv('SYNTHETIC_CALLBACK_CONFIG')?:throw new RuntimeException('callback_not_configured');
    $config=JsonDocument::decode(file_get_contents($file),true);
    if(!is_array($config)||!is_string($config['payload_key_hex']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$config['payload_key_hex'])||!is_string($config['replay_key_hex']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$config['replay_key_hex'])){throw new RuntimeException('callback_not_configured');}
    $receiver=new SyntheticCallbackReceiver($config['state'],$config['endpoints'],hex2bin($config['payload_key_hex']),new SyntheticReplayAuthority(hex2bin($config['replay_key_hex'])));
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($_SERVER['REQUEST_METHOD']==='POST'&&preg_match('~\A/callbacks/([a-f0-9]{32})\z~D',$path,$matches)){
        $body=file_get_contents('php://input',false,null,0,262145);
        $result=$receiver->receive($matches[1],$body,$_SERVER['HTTP_X_SYNTHETIC_TIMESTAMP']??'',$_SERVER['HTTP_X_SYNTHETIC_DELIVERY_ID']??'',$_SERVER['HTTP_X_SYNTHETIC_SIGNATURE']??'');http_response_code($result['duplicate']?200:202);
    }elseif($_SERVER['REQUEST_METHOD']==='POST'&&preg_match('~\A/receipts/([a-f0-9]{32})/replay\z~D',$path,$matches)){
        if(!str_starts_with($_SERVER['HTTP_AUTHORIZATION']??'','Bearer ')){throw new InvalidArgumentException('replay_unauthorized');}
        $result=$receiver->replay($matches[1],substr($_SERVER['HTTP_AUTHORIZATION'],7));
    }else{http_response_code(404);$result=['error'=>'not_found'];}
    echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable $error){http_response_code(422);echo json_encode(['error'=>'callback_rejected','reason_code'=>preg_match('/\A[a-z_]+\z/D',$error->getMessage())?$error->getMessage():'invalid_request'],JSON_THROW_ON_ERROR);}
