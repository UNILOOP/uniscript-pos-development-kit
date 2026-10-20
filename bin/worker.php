<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';
$platform=new UniScript\Integration\Platform(getenv('PLATFORM_STATE')?:dirname(__DIR__).'/var/platform');
do{$processed=$platform->work();echo json_encode(['processed'=>$processed],JSON_THROW_ON_ERROR)."\n";if(in_array('--once',$argv,true)){break;}sleep(1);}while(true);
