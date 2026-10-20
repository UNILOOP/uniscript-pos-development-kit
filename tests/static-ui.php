<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Manifest;
use UniScript\Integration\StaticUi;

$count=0;
function check(bool $ok):void {global $count;$count++;if(!$ok){throw new RuntimeException('assertion_failed_'.$count);}}
$root=dirname(__DIR__).'/examples/custom-ui';$manifest=Manifest::read($root);$ui=StaticUi::inspect($root,$manifest);
check($manifest['version']==='1.0.3');check(count($ui['files'])===4);check(strlen($ui['sha256'])===64);
$files=$ui['files'];ksort($files,SORT_STRING);check($ui['sha256']===hash('sha256',json_encode($files,JSON_THROW_ON_ERROR)));
check(StaticUi::inspect(dirname(__DIR__).'/examples/demo',Manifest::read(dirname(__DIR__).'/examples/demo'))['sha256']===hash('sha256',''));
foreach([['profile'=>'static-v1','entrypoint'=>'ui/../index.html'],['profile'=>'static-v1','entrypoint'=>'ui/server.php'],['profile'=>'static-v1','entrypoint'=>'ui/index.html','url'=>'https://unsafe.invalid'],['profile'=>'unknown','entrypoint'=>'ui/index.html']] as $definition){
    try{StaticUi::inspect($root,[...$manifest,'ui'=>$definition]);throw new RuntimeException('invalid_ui_accepted');}catch(InvalidArgumentException){check(true);}
}
$old=$manifest;$old['manifest_version']=1;try{StaticUi::inspect($root,$old);throw new RuntimeException('v1_ui_accepted');}catch(InvalidArgumentException){check(true);}
echo json_encode(['result'=>'passed','assertions'=>$count,'ui_sha256'=>$ui['sha256']],JSON_THROW_ON_ERROR)."\n";
