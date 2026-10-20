<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\SyntheticCallbackReceiver;
use UniScript\Integration\SyntheticReplayAuthority;

$assertions=0;function check(bool $condition,string $message):void{global $assertions;$assertions++;if(!$condition){throw new RuntimeException($message);}}
function denied(callable $operation):void{try{$operation();}catch(Throwable){check(true,'denied');return;}check(false,'expected denial');}
$state=sys_get_temp_dir().'/kit-callback-'.bin2hex(random_bytes(6));$endpointId=bin2hex(random_bytes(16));$key=bin2hex(random_bytes(32));$payloadKey=random_bytes(32);$replayKey=random_bytes(32);$now=time();$authority=new SyntheticReplayAuthority($replayKey,static function()use(&$now):int{return $now;});
$scope=['package_id'=>'uniscript/lifefile','organization_id'=>'organization-a','location_id'=>'location-a','connection_id'=>'connection-a','environment'=>'sandbox'];
$ownership=[...$scope,'order_handle'=>'order-a','attempt_handle'=>'attempt-a','execution_revision'=>'originating-release'];
$endpoint=[...$scope,'provider_account_handle'=>'provider-account-a','execution_revision'=>'current-release','signing_key'=>$key,'references'=>['vendor-order-a'=>[$ownership],'ambiguous'=>[$ownership,$ownership]]];
$receiver=new SyntheticCallbackReceiver($state,[$endpointId=>$endpoint],$payloadKey,$authority);
$body=json_encode(['external_reference'=>'vendor-order-a','connection_id'=>'spoofed-connection','organization_id'=>'spoofed-organization','events'=>[['eventId'=>'event-a','orderId'=>'vendor-order-a','rxStatus'=>'Filled','patient'=>'SYNTHETIC-SENSITIVE-MARKER']]],JSON_THROW_ON_ERROR);
function receive(SyntheticCallbackReceiver $receiver,string $body,string $id='delivery-a',?string $timestamp=null,?string $signature=null):array{global $endpointId,$key;$timestamp??=(string)time();$signature??=hash_hmac('sha256',$timestamp.'.'.$id.'.'.$body,$key);return $receiver->receive($endpointId,$body,$timestamp,$id,$signature);}
$receipt=receive($receiver,$body);check($receipt['state']==='matched'&&!$receipt['duplicate'],'scoped callback matched');check(receive($receiver,$body)['receipt_handle']===$receipt['receipt_handle'],'duplicate stable receipt');
$restarted=new SyntheticCallbackReceiver($state,[$endpointId=>$endpoint],$payloadKey,$authority);check(receive($restarted,$body)['duplicate']===true,'durable duplicate after restart');
$upgradedId=bin2hex(random_bytes(16));$upgraded=new SyntheticCallbackReceiver($state,[$upgradedId=>[...$endpoint,'execution_revision'=>'new-release']],$payloadKey,$authority);
$stamp=(string)time();$sig=hash_hmac('sha256',$stamp.'.delivery-a.'.$body,$key);check($upgraded->receive($upgradedId,$body,$stamp,'delivery-a',$sig)['receipt_handle']===$receipt['receipt_handle'],'cross release deduplication');
denied(fn()=>receive($receiver,str_replace('Filled','Shipped',$body)));denied(fn()=>receive($receiver,$body,'forged',signature:str_repeat('0',64)));denied(fn()=>receive($receiver,$body,'stale',(string)(time()-301)));denied(fn()=>receive($receiver,$body,'future',(string)(time()+301)));
$metadata=file_get_contents($state.'/receipts/'.$receipt['receipt_handle'].'.json');check(!str_contains($metadata,'SYNTHETIC-SENSITIVE-MARKER'),'ordinary metadata excludes payload');check(!str_contains(file_get_contents($state.'/payloads/'.$receipt['receipt_handle'].'.bin'),'SYNTHETIC-SENSITIVE-MARKER'),'raw payload encrypted');
$claims=['actor_id'=>'actor-a','receipt_handle'=>$receipt['receipt_handle'],...$scope,'expected_revision'=>1];
$grant=$authority->issue($claims);$replay=$receiver->replay($receipt['receipt_handle'],$grant);check($replay['applied']===false&&$replay['decoder_execution_revision']==='originating-release','original decoder and proposal');check($replay['scope']['connection_id']==='connection-a','callback body cannot alter scope');check($replay['originating_order_handle']==='order-a'&&$replay['originating_attempt_handle']==='attempt-a','originating attempt ownership');check($replay['revision']===2&&$replay['input']['authenticated_receipt']===true,'authorized decode input');denied(fn()=>$receiver->replay($receipt['receipt_handle'],$grant));
foreach(['connection_id','organization_id','location_id','environment','package_id'] as $field){denied(fn()=>$receiver->replay($receipt['receipt_handle'],$authority->issue(array_replace($claims,[$field=>'wrong','expected_revision'=>2]))));}
$expired=$authority->issue(array_replace($claims,['expected_revision'=>2]),1);$now+=2;denied(fn()=>$receiver->replay($receipt['receipt_handle'],$expired));$now=time();
$parts=explode('.',$authority->issue(array_replace($claims,['expected_revision'=>2])));$parts[0]=strrev($parts[0]);denied(fn()=>$receiver->replay($receipt['receipt_handle'],implode('.',$parts)));
$unmatched=receive($receiver,json_encode(['external_reference'=>'unknown','events'=>[['eventId'=>'event-unmatched']]],JSON_THROW_ON_ERROR),'unmatched');check($unmatched['state']==='unmatched','unknown ownership queued');denied(fn()=>$receiver->replay($unmatched['receipt_handle'],$authority->issue(array_replace($claims,['receipt_handle'=>$unmatched['receipt_handle']]))));
$ambiguous=receive($receiver,json_encode(['external_reference'=>'ambiguous','events'=>[['eventId'=>'event-ambiguous']]],JSON_THROW_ON_ERROR),'ambiguous');check($ambiguous['state']==='review_required','ambiguous ownership held');
$cross=$endpoint;$cross['connection_id']='connection-other';denied(fn()=>new SyntheticCallbackReceiver($state,[$endpointId=>$endpoint,bin2hex(random_bytes(16))=>$cross],$payloadKey,$authority));
$production=$endpoint;$production['environment']='production';denied(fn()=>new SyntheticCallbackReceiver($state,[$endpointId=>$production],$payloadKey,$authority));
$wrongClass=rtrim(strtr(base64_encode(json_encode(['purpose'=>'http.request','issued_at'=>time(),'expires_at'=>time()+60,...$claims],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
denied(fn()=>$authority->verify($wrongClass.'.'.hash_hmac('sha256',$wrongClass,$replayKey)));
$corrupted=file_get_contents($state.'/payloads/'.$receipt['receipt_handle'].'.bin');$corrupted[strlen($corrupted)-1]=chr(ord($corrupted[strlen($corrupted)-1])^1);file_put_contents($state.'/payloads/'.$receipt['receipt_handle'].'.bin',$corrupted);
denied(fn()=>$receiver->replay($receipt['receipt_handle'],$authority->issue(array_replace($claims,['expected_revision'=>2]))));
echo json_encode(['result'=>'passed','assertions'=>$assertions],JSON_THROW_ON_ERROR)."\n";
