<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

final class JsonValue
{
    public static function assert(mixed $value,int $depth=0):void
    {
        if($depth>32){throw new InvalidArgumentException('json_depth');}
        if(is_array($value)){foreach($value as $key=>$child){if(!is_int($key)&&!is_string($key)){throw new InvalidArgumentException('json_key');}self::assert($child,$depth+1);}return;}
        if($value!==null&&!is_string($value)&&!is_int($value)&&!is_bool($value)&&!(is_float($value)&&is_finite($value))){throw new InvalidArgumentException('json_value');}
        if(is_string($value)&&preg_match('//u',$value)!==1){throw new InvalidArgumentException('json_utf8');}
    }

    public static function object(array $value):bool{return $value===[]||!array_is_list($value);}
}
