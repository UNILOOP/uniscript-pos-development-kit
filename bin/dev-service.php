<?php

declare(strict_types=1);
$role=$argv[1]??'';
if(is_file('/config/environment.json')){$values=json_decode(file_get_contents('/config/environment.json'),true,32,JSON_THROW_ON_ERROR);foreach($values as $name=>$value){if(is_string($value)){putenv($name.'='.$value);}}}
if($role==='callback'){putenv('SYNTHETIC_CALLBACK_CONFIG=/config/configuration.json');}
$script=match($role){'control-plane'=>'control-plane.php','worker'=>'worker.php','broker'=>'dev-broker.php','mock'=>'mock-pos.php','callback'=>'callback-receiver.php','operator'=>'dev-operator.php',default=>throw new InvalidArgumentException('unknown_local_role')};
$command=$role==='worker'?[PHP_BINARY,__DIR__.'/'.$script]:[PHP_BINARY,'-S','0.0.0.0:8080',__DIR__.'/'.$script];
$process=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),getenv());exit(proc_close($process));
