<?php

declare(strict_types=1);

/** Trusted static-only router. Start PHP's server with an otherwise empty document root. */
function rejectAsset(int $status): never
{
    http_response_code($status);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');echo 'Package screen unavailable.';exit;
}
$root=realpath(getenv('PACKAGE_UI_ROOT')?:'');$origin=getenv('PACKAGE_UI_ORIGIN')?:'';$hostOrigin=getenv('PACKAGE_UI_HOST_ORIGIN')?:'';
if($root===false||!preg_match('/\Ahttp:\/\/(127\.0\.0\.2|localhost):([1-9][0-9]{0,4})\z/D',$origin,$match)||!preg_match('/\Ahttp:\/\/(?:127\.0\.0\.1|localhost)(?::[1-9][0-9]{0,4})?\z/D',$hostOrigin)){rejectAsset(503);}
if(($match[1]==='localhost'&&!str_starts_with($hostOrigin,'http://127.0.0.1'))||($_SERVER['HTTP_HOST']??'')!==$match[1].':'.$match[2]||($_SERVER['SERVER_ADDR']??($match[1]==='localhost'?'127.0.0.1':'127.0.0.2'))!==($match[1]==='localhost'?'127.0.0.1':'127.0.0.2')){rejectAsset(421);}
if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','HEAD'],true)){rejectAsset(405);}
$metadata=@file_get_contents($root.'/assets.json',false,null,0,65537);
if(!is_string($metadata)||strlen($metadata)>65536){rejectAsset(503);}
try{$index=json_decode($metadata,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){rejectAsset(503);}
$files=$index['files']??null;if(!is_array($files)||count($files)>100){rejectAsset(503);}ksort($files,SORT_STRING);
$digest=hash('sha256',json_encode($files,JSON_THROW_ON_ERROR));
if(!hash_equals($index['digest']??'',$digest)){rejectAsset(503);}
$uri=$_SERVER['REQUEST_URI']??'';
if(!preg_match('/\A\/assets\/([a-f0-9]{64})\/(?:[A-Za-z0-9][A-Za-z0-9_-]*\/)*[A-Za-z0-9][A-Za-z0-9_-]*\.(?:html|js|css)\z/D',$uri,$path)||$path[1]!==$digest){rejectAsset(404);}
$relative=substr($uri,73);$file=realpath($root.'/'.$relative);
if(!isset($files[$relative])||$file===false||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||is_link($root.'/'.$relative)||!is_file($file)||filesize($file)>2097152){rejectAsset(404);}
$body=file_get_contents($file);if(!hash_equals($files[$relative],hash('sha256',$body))){rejectAsset(503);}
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'none'; img-src 'none'; font-src 'none'; frame-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors ".$hostOrigin."; sandbox allow-scripts allow-same-origin");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), clipboard-read=(), clipboard-write=()');
header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Type: '.match(pathinfo($relative,PATHINFO_EXTENSION)){'html'=>'text/html; charset=utf-8','js'=>'application/javascript; charset=utf-8','css'=>'text/css; charset=utf-8'});
header('Content-Length: '.strlen($body));if(($_SERVER['REQUEST_METHOD']??'')!=='HEAD'){echo $body;}
