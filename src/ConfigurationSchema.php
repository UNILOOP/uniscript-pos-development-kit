<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use stdClass;

/** Host-compatible manifest-v2 scalar configuration, credential and resource profile. */
final class ConfigurationSchema
{
    public static function assertDefinition(mixed $schema,bool $credentials=false):void
    {
        if(!$schema instanceof stdClass||array_diff(array_keys(get_object_vars($schema)),['type','title','description','properties','required','additionalProperties'])!==[]||($schema->type??null)!=='object'||($schema->additionalProperties??null)!==false||!($schema->properties??null) instanceof stdClass||count(get_object_vars($schema->properties))>50||!is_array($schema->required??null)||count(array_unique($schema->required,SORT_REGULAR))!==count($schema->required)){throw new InvalidArgumentException('configuration_schema_unsupported');}
        self::labels($schema);
        foreach(get_object_vars($schema->properties) as $name=>$field){
            if(!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$name)||!$field instanceof stdClass||array_diff(array_keys(get_object_vars($field)),['type','title','description','enum','minLength','maxLength','minimum','maximum'])!==[]||!in_array($field->type??null,$credentials?['string']:['string','boolean','integer','number'],true)){throw new InvalidArgumentException('configuration_field_unsupported');}
            self::labels($field);
            foreach(['minLength','maxLength'] as $key){if(property_exists($field,$key)&&($field->type!=='string'||!is_int($field->{$key})||$field->{$key}<0||$field->{$key}>4096)){throw new InvalidArgumentException('configuration_string_bound');}}
            foreach(['minimum','maximum'] as $key){if(property_exists($field,$key)&&(!in_array($field->type,['integer','number'],true)||!self::type($field->{$key},'number'))){throw new InvalidArgumentException('configuration_number_bound');}}
            if(($field->minLength??0)>($field->maxLength??4096)||(isset($field->minimum,$field->maximum)&&$field->minimum>$field->maximum)){throw new InvalidArgumentException('configuration_bounds_order');}
            if(property_exists($field,'enum')){
                if(!is_array($field->enum)||count($field->enum)<1||count($field->enum)>100){throw new InvalidArgumentException('configuration_enum');}
                foreach($field->enum as $value){if(!self::value($value,$field,false)){throw new InvalidArgumentException('configuration_enum_value');}}
            }
        }
        foreach($schema->required as $name){if(!is_string($name)||!property_exists($schema->properties,$name)){throw new InvalidArgumentException('configuration_required');}}
    }

    public static function validateValues(stdClass $schema,array $values,bool $credentials=false):void
    {
        self::assertDefinition($schema,$credentials);
        if(!JsonValue::object($values)){throw new InvalidArgumentException('configuration_values_object');}
        foreach($schema->required as $name){if(!array_key_exists($name,$values)){throw new InvalidArgumentException('configuration_value_required');}}
        foreach($values as $name=>$value){if(!property_exists($schema->properties,$name)||!self::value($value,$schema->properties->{$name})){throw new InvalidArgumentException('configuration_value_invalid');}}
    }

    private static function value(mixed $value,stdClass $field,bool $withEnum=true):bool
    {
        if(!self::type($value,$field->type)){return false;}
        if(is_string($value)&&(($length=preg_match_all('/./us',$value))===false||$length<($field->minLength??0)||$length>($field->maxLength??4096))){return false;}
        if((is_int($value)||is_float($value))&&((isset($field->minimum)&&$value<$field->minimum)||(isset($field->maximum)&&$value>$field->maximum))){return false;}
        return !$withEnum||!property_exists($field,'enum')||in_array($value,$field->enum,true);
    }

    private static function type(mixed $value,string $type):bool
    {
        return match($type){'string'=>is_string($value),'boolean'=>is_bool($value),'integer'=>is_int($value),'number'=>(is_int($value)||is_float($value))&&is_finite((float)$value),default=>false};
    }

    private static function labels(stdClass $definition):void
    {
        foreach(['title'=>120,'description'=>500] as $key=>$limit){if(property_exists($definition,$key)&&(!is_string($definition->{$key})||preg_match_all('/./us',$definition->{$key})>$limit||preg_match('/[<>\x00-\x1f\x7f]/u',$definition->{$key})!==0)){throw new InvalidArgumentException('configuration_label');}}
    }
}
