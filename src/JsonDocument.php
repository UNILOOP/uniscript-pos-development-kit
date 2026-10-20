<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

final class JsonDocument
{
    public static function decode(string $json,bool $associative=false):mixed
    {
        $value=json_decode($json,$associative,32,JSON_THROW_ON_ERROR);
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:]+/s',$json,$tokens);$stack=[];
        foreach($tokens[0] as $token){
            if($token==='{'||$token==='['){$stack[]=['object'=>$token==='{','key'=>true,'keys'=>[]];}
            elseif($token==='}'||$token===']'){array_pop($stack);}
            elseif($stack!==[]){$index=array_key_last($stack);if($token===','&&$stack[$index]['object']){$stack[$index]['key']=true;}elseif(str_starts_with($token,'"')&&$stack[$index]['object']&&$stack[$index]['key']){$key=json_decode($token,true,2,JSON_THROW_ON_ERROR);if(isset($stack[$index]['keys'][$key])){throw new InvalidArgumentException('json_duplicate_key');}$stack[$index]['keys'][$key]=true;$stack[$index]['key']=false;}}
        }
        return $value;
    }
}
