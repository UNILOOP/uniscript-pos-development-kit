<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use stdClass;

/** Deliberately bounded JSON Schema subset; unsupported keywords fail closed. */
final class SchemaValidator
{
    private array $documents = [];
    private const array KEYWORDS = ['$schema','$ref','$defs','definitions','title','description','type','enum','const','required','properties','additionalProperties','items','minItems','maxItems','minLength','maxLength','minimum','maximum'];

    public function __construct(private readonly string $packageRoot) {}

    public function validate(mixed $value, string $schemaPath): void
    {
        $schema=$this->load($schemaPath);
        $this->assertSchema($schema,$schemaPath,0,[]);
        $this->check($value,$schema,$schemaPath,'$',0);
    }

    private function load(string $path): stdClass|bool
    {
        Manifest::path($path);
        if(isset($this->documents[$path])){return $this->documents[$path];}
        $root=realpath($this->packageRoot);$file=realpath($this->packageRoot.'/'.$path);
        if($root===false||$file===false||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||is_link($this->packageRoot.'/'.$path)||!is_file($file)||filesize($file)>262144){throw new InvalidArgumentException('schema_file_invalid');}
        $document=JsonDocument::decode(file_get_contents($file));
        if(!$document instanceof stdClass&&!is_bool($document)){throw new InvalidArgumentException('schema_object_required');}
        return $this->documents[$path]=$document;
    }

    private function resolve(string $reference,string $document):array
    {
        if(strlen($reference)>500||str_contains($reference,':')||str_contains($reference,'\\')){throw new InvalidArgumentException('schema_remote_reference');}
        [$file,$fragment]=array_pad(explode('#',$reference,2),2,'');
        if($file!==''){
            Manifest::path($file);
            $document=(dirname($document)==='.'?'':dirname($document).'/').$file;
        }
        $target=$this->load($document);
        if($fragment!==''){
            if(!str_starts_with($fragment,'/')){throw new InvalidArgumentException('schema_reference_fragment');}
            foreach(explode('/',substr($fragment,1)) as $part){$part=str_replace(['~1','~0'],['/','~'],$part);if(!$target instanceof stdClass||!property_exists($target,$part)){throw new InvalidArgumentException('schema_reference_missing');}$target=$target->{$part};}
        }
        if(!$target instanceof stdClass&&!is_bool($target)){throw new InvalidArgumentException('schema_reference_invalid');}
        return [$target,$document];
    }

    private function assertSchema(stdClass|bool $schema,string $document,int $depth,array $references):void
    {
        if($depth>40){throw new InvalidArgumentException('schema_depth');}
        if(is_bool($schema)){return;}
        if(array_diff(array_keys(get_object_vars($schema)),self::KEYWORDS)!==[]){throw new InvalidArgumentException('schema_keyword_unsupported');}
        foreach(['title','description','$schema'] as $metadata){if(isset($schema->{$metadata})&&!is_string($schema->{$metadata})){throw new InvalidArgumentException('schema_metadata');}}
        if(isset($schema->{'$schema'})&&!in_array($schema->{'$schema'},['https://json-schema.org/draft/2020-12/schema','http://json-schema.org/draft-07/schema#'],true)){throw new InvalidArgumentException('schema_draft_unsupported');}
        if(property_exists($schema,'type')){
            $types=is_array($schema->type)?$schema->type:[$schema->type];
            if($types===[]||count(array_unique($types,SORT_REGULAR))!==count($types)){throw new InvalidArgumentException('schema_type');}
            foreach($types as $type){if(!is_string($type)||!in_array($type,['object','array','string','integer','number','boolean','null'],true)){throw new InvalidArgumentException('schema_type');}}
        }
        if(property_exists($schema,'enum')&&(!is_array($schema->enum)||$schema->enum===[])){throw new InvalidArgumentException('schema_enum');}
        if(property_exists($schema,'required')){if(!is_array($schema->required)||count(array_unique($schema->required,SORT_REGULAR))!==count($schema->required)){throw new InvalidArgumentException('schema_required');}foreach($schema->required as $key){if(!is_string($key)){throw new InvalidArgumentException('schema_required');}}}
        foreach(['minItems','maxItems','minLength','maxLength'] as $key){if(property_exists($schema,$key)&&(!is_int($schema->{$key})||$schema->{$key}<0||$schema->{$key}>1048576)){throw new InvalidArgumentException('schema_bound');}}
        foreach(['minimum','maximum'] as $key){if(property_exists($schema,$key)&&(!is_int($schema->{$key})&&!is_float($schema->{$key}))){throw new InvalidArgumentException('schema_bound');}}
        foreach(['properties','$defs','definitions'] as $group){if(property_exists($schema,$group)){if(!$schema->{$group} instanceof stdClass){throw new InvalidArgumentException('schema_properties');}foreach(get_object_vars($schema->{$group}) as $child){if(!$child instanceof stdClass&&!is_bool($child)){throw new InvalidArgumentException('schema_child');}$this->assertSchema($child,$document,$depth+1,$references);}}}
        foreach(['additionalProperties','items'] as $key){if(property_exists($schema,$key)){if(!$schema->{$key} instanceof stdClass&&!is_bool($schema->{$key})){throw new InvalidArgumentException('schema_child');}$this->assertSchema($schema->{$key},$document,$depth+1,$references);}}
        if(property_exists($schema,'$ref')){
            if(!is_string($schema->{'$ref'})){throw new InvalidArgumentException('schema_reference_invalid');}
            $identity=$document.'#'.$schema->{'$ref'};
            if(isset($references[$identity])){throw new InvalidArgumentException('schema_reference_cycle');}
            $references[$identity]=true;[$target,$next]=$this->resolve($schema->{'$ref'},$document);$this->assertSchema($target,$next,$depth+1,$references);
        }
    }

    private function check(mixed $value,stdClass|bool $schema,string $document,string $field,int $depth):void
    {
        if($depth>40){throw new InvalidArgumentException('schema_value_depth');}
        if($schema===true){return;}if($schema===false){throw new InvalidArgumentException('schema_rejected:'.$field);}
        if(isset($schema->{'$ref'})){[$target,$next]=$this->resolve($schema->{'$ref'},$document);$this->check($value,$target,$next,$field,$depth+1);}
        if(property_exists($schema,'type')){
            $types=is_array($schema->type)?$schema->type:[$schema->type];$valid=false;
            foreach($types as $type){$valid=$valid||match($type){'object'=>$value instanceof stdClass||(is_array($value)&&($value===[]||!array_is_list($value))),'array'=>is_array($value)&&array_is_list($value),'string'=>is_string($value),'integer'=>is_int($value),'number'=>is_int($value)||(is_float($value)&&is_finite($value)),'boolean'=>is_bool($value),'null'=>$value===null};}
            if(!$valid){throw new InvalidArgumentException('schema_type:'.$field);}
        }
        if(property_exists($schema,'enum')&&!array_any($schema->enum,static fn(mixed $candidate):bool=>self::equal($value,$candidate))){throw new InvalidArgumentException('schema_enum:'.$field);}
        if(property_exists($schema,'const')&&!self::equal($value,$schema->const)){throw new InvalidArgumentException('schema_const:'.$field);}
        if($value instanceof stdClass||(is_array($value)&&($value===[]||!array_is_list($value)))){
            $values=$value instanceof stdClass?get_object_vars($value):$value;
            foreach($schema->required??[] as $required){if(!array_key_exists($required,$values)){throw new InvalidArgumentException('schema_required:'.$field.'.'.$required);}}
            $properties=isset($schema->properties)?get_object_vars($schema->properties):[];
            foreach($values as $key=>$child){if(isset($properties[$key])||array_key_exists($key,$properties)){$this->check($child,$properties[$key],$document,$field.'.'.$key,$depth+1);}elseif(property_exists($schema,'additionalProperties')){$this->check($child,$schema->additionalProperties,$document,$field.'.'.$key,$depth+1);}}
        }
        if(is_array($value)&&array_is_list($value)){
            if((isset($schema->minItems)&&count($value)<$schema->minItems)||(isset($schema->maxItems)&&count($value)>$schema->maxItems)){throw new InvalidArgumentException('schema_array_length:'.$field);}
            if(property_exists($schema,'items')){foreach($value as $index=>$child){$this->check($child,$schema->items,$document,$field.'['.$index.']',$depth+1);}}
        }
        if(is_string($value)){$length=preg_match_all('/./us',$value);if($length===false||(isset($schema->minLength)&&$length<$schema->minLength)||(isset($schema->maxLength)&&$length>$schema->maxLength)){throw new InvalidArgumentException('schema_string_length:'.$field);}}
        if((is_int($value)||is_float($value))&&((isset($schema->minimum)&&$value<$schema->minimum)||(isset($schema->maximum)&&$value>$schema->maximum))){throw new InvalidArgumentException('schema_number_bound:'.$field);}
    }

    private static function equal(mixed $left,mixed $right):bool
    {
        if($left instanceof stdClass){$left=get_object_vars($left);}if($right instanceof stdClass){$right=get_object_vars($right);}
        if(is_array($left)&&is_array($right)){if(count($left)!==count($right)||array_is_list($left)!==array_is_list($right)){return false;}foreach($left as $key=>$value){if(!array_key_exists($key,$right)||!self::equal($value,$right[$key])){return false;}}return true;}
        if((is_int($left)||is_float($left))&&(is_int($right)||is_float($right))){return $left==$right;}
        return $left===$right;
    }
}
