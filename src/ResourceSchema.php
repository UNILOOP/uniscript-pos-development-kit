<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use stdClass;

/** Bounded host-compatible package resource schema; configuration remains flat. */
final class ResourceSchema
{
    public static function assertDefinition(mixed $schema):void
    {
        if(!$schema instanceof stdClass||($schema->type??null)!=='object'){throw new InvalidArgumentException('resource_object_root_required');}$nodes=0;self::definition($schema,0,$nodes);
    }
    public static function validateValues(stdClass $schema,array $value):void
    {
        self::assertDefinition($schema);self::value($schema,$value);
    }
    public static function assertWireDefinition(mixed $schema):void
    {
        if(!$schema instanceof stdClass||($schema->type??null)!=='object'){throw new InvalidArgumentException('wire_object_root_required');}
        $nodes=0;self::definition($schema,0,$nodes,true);
    }
    public static function validateWireValues(stdClass $schema,array $value):void
    {
        self::assertWireDefinition($schema);self::value($schema,$value);
    }
    private static function definition(stdClass $schema,int $depth,int &$nodes,bool $wireKeys=false):void
    {
        if($depth>6||++$nodes>200){throw new InvalidArgumentException('resource_schema_budget');}
        $type=$schema->type??null;
        if(!in_array($type,['object','array'],true)){ConfigurationSchema::assertDefinition(self::scalarObject($schema));return;}
        $allowed=$type==='object'?['type','properties','required','additionalProperties','title','description']:['type','items','minItems','maxItems','title','description'];
        if(array_diff(array_keys(get_object_vars($schema)),$allowed)!==[]){throw new InvalidArgumentException('resource_keyword_unsupported');}
        $labels=(object)['type'=>'object','properties'=>(object)[],'required'=>[],'additionalProperties'=>false];foreach(['title','description'] as $key){if(property_exists($schema,$key)){$labels->{$key}=$schema->{$key};}}ConfigurationSchema::assertDefinition($labels);
        if($type==='array'){
            if(!($schema->items??null) instanceof stdClass||!is_int($schema->maxItems??null)||$schema->maxItems<1||$schema->maxItems>1000||(property_exists($schema,'minItems')&&(!is_int($schema->minItems)||$schema->minItems<0||$schema->minItems>$schema->maxItems))){throw new InvalidArgumentException('resource_array_bounds');}self::definition($schema->items,$depth+1,$nodes,$wireKeys);return;
        }
        if(($schema->additionalProperties??null)!==false||!($schema->properties??null) instanceof stdClass||count(get_object_vars($schema->properties))>50||!is_array($schema->required??null)||count($schema->required)>50||count(array_unique($schema->required,SORT_REGULAR))!==count($schema->required)){throw new InvalidArgumentException('resource_object_definition');}
        foreach($schema->required as $name){if(!is_string($name)||!property_exists($schema->properties,$name)){throw new InvalidArgumentException('resource_required');}}
        foreach(get_object_vars($schema->properties) as $name=>$child){if(!preg_match($wireKeys?'/\A[A-Za-z_][A-Za-z0-9_-]{0,99}\z/D':'/\A[a-z][a-z0-9_]{0,63}\z/D',$name)||($wireKeys&&in_array($name,['__proto__','constructor','prototype'],true))||!$child instanceof stdClass){throw new InvalidArgumentException('resource_field');}self::definition($child,$depth+1,$nodes,$wireKeys);}
    }
    private static function value(stdClass $schema,mixed $value):void
    {
        if($schema->type==='object'){
            if(!is_array($value)||!JsonValue::object($value)||array_diff(array_keys($value),array_keys(get_object_vars($schema->properties)))!==[]||array_diff($schema->required,array_keys($value))!==[]){throw new InvalidArgumentException('resource_object_value');}foreach($value as $key=>$child){self::value($schema->properties->{$key},$child);}
        }elseif($schema->type==='array'){
            if(!is_array($value)||!array_is_list($value)||count($value)>$schema->maxItems||count($value)<($schema->minItems??0)){throw new InvalidArgumentException('resource_array_value');}foreach($value as $child){self::value($schema->items,$child);}
        }else{ConfigurationSchema::validateValues(self::scalarObject($schema),['value'=>$value]);}
    }
    private static function scalarObject(stdClass $scalar):stdClass{return (object)['type'=>'object','properties'=>(object)['value'=>$scalar],'required'=>['value'],'additionalProperties'=>false];}
}
