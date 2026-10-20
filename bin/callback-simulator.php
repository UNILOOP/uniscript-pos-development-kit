<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\JsonDocument;

$options=getopt('',['config:','endpoint:','url:','delivery:','file:']);
foreach(['config','endpoint','url','delivery','file'] as $key){if(!is_string($options[$key]??null)){throw new InvalidArgumentException('Required flags: --config --endpoint --url --delivery --file');}}
$url=parse_url($options['url']);
if(!is_array($url)||($url['scheme']??null)!=='http'||!in_array($url['host']??null,['localhost','127.0.0.1','::1','[::1]'],true)||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!in_array($url['path']??'',['','/'],true)){throw new InvalidArgumentException('synthetic_loopback_url_required');}
if(!preg_match('/\A[a-f0-9]{32}\z/D',$options['endpoint'])||!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D',$options['delivery'])){throw new InvalidArgumentException('synthetic_identifiers_invalid');}
$config=JsonDocument::decode(file_get_contents($options['config']),true);$endpoint=$config['endpoints'][$options['endpoint']]??throw new InvalidArgumentException('endpoint_not_configured');
if(!in_array($endpoint['environment']??null,['local','sandbox','dev'],true)){throw new InvalidArgumentException('production_disabled');}
$body=file_get_contents($options['file'],false,null,0,262145);if($body===false||strlen($body)>262144){throw new InvalidArgumentException('synthetic_body_limit');}
$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp.'.'.$options['delivery'].'.'.$body,$endpoint['signing_key']);
$context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-Synthetic-Timestamp: ".$timestamp."\r\nX-Synthetic-Delivery-Id: ".$options['delivery']."\r\nX-Synthetic-Signature: ".$signature."\r\n",'content'=>$body,'timeout'=>5,'follow_location'=>0,'ignore_errors'=>true]]);
$response=file_get_contents(rtrim($options['url'],'/').'/callbacks/'.$options['endpoint'],false,$context,0,8193);$headers=http_get_last_response_headers();
if($response===false||strlen($response)>8192||!isset($headers[0])||!preg_match('~\s2\d\d\s~',$headers[0])){throw new RuntimeException('synthetic_callback_rejected');}
$result=JsonDocument::decode($response,true);if(!preg_match('/\A[a-f0-9]{32}\z/D',$result['receipt_handle']??'')||!is_string($result['state']??null)||!is_bool($result['duplicate']??null)){throw new RuntimeException('synthetic_receiver_result_invalid');}
echo json_encode(array_intersect_key($result,array_flip(['receipt_handle','state','duplicate'])),JSON_THROW_ON_ERROR)."\n";
