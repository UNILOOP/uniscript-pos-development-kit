<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

final class Manifest
{
    public static function read(string $root): array
    {
        $json = file_get_contents($root.'/integration.json');
        if ($json === false || strlen($json) > 65536) { throw new InvalidArgumentException('manifest_unreadable'); }
        $manifest = JsonDocument::decode($json, true);
        $contract = HostContract::read();
        self::metadata($manifest, JsonDocument::decode($json), $contract);
        $keys = ['manifest_version', 'package_id', 'publisher', 'integration_key', 'display_name', 'vendor_family', 'version', 'sdk_version', 'protocol_version', 'runtime', 'operations', 'capabilities', 'support'];
        $version=$manifest['manifest_version']??null;
        $required=$keys;
        if($version===2){$required=[...$required,'configuration_schema','credential_schema'];$keys=[...$keys,'configuration_schema','credential_schema','resource_schemas','ui'];}
        if (!is_array($manifest) || array_diff($required, array_keys($manifest)) !== [] || array_diff(array_keys($manifest), $keys) !== []) { throw new InvalidArgumentException('manifest_fields'); }
        if (!in_array($manifest['manifest_version'],[1,2],true) || $manifest['protocol_version'] !== '1' || $manifest['sdk_version'] !== '^1.0' || !str_starts_with($manifest['package_id'], $manifest['publisher'].'/')) { throw new InvalidArgumentException('manifest_identity'); }
        if (($manifest['runtime']['php'] ?? null) !== '^8.4' || ($manifest['runtime']['profile'] ?? null) !== 'standard') { throw new InvalidArgumentException('manifest_runtime'); }
        self::path($manifest['runtime']['entrypoint']);
        if (!is_file($root.'/'.$manifest['runtime']['entrypoint'])) { throw new InvalidArgumentException('missing_entrypoint'); }
        $seen = [];
        foreach ($manifest['operations'] as $operation) {
            if (!is_string($operation['key'] ?? null) || isset($seen[$operation['key']]) || !preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]*)+\z/D', $operation['key'])) { throw new InvalidArgumentException('invalid_operation'); }
            $seen[$operation['key']] = true;
            foreach (['input_schema', 'output_schema'] as $field) {
                self::path($operation[$field]);
                if (!is_file($root.'/'.$operation[$field])) { throw new InvalidArgumentException('missing_schema'); }
                JsonDocument::decode(file_get_contents($root.'/'.$operation[$field]), true);
            }
        }
        if($version===2){
            $object=JsonDocument::decode($json);
            foreach(['configuration_schema'=>false,'credential_schema'=>true] as $field=>$credentials){
                self::schemaFile($root,$manifest[$field],$credentials);
            }
            if(property_exists($object,'resource_schemas')){
                if(!$object->resource_schemas instanceof \stdClass||count(get_object_vars($object->resource_schemas))>50){throw new InvalidArgumentException('resource_schemas_object');}
                foreach(get_object_vars($object->resource_schemas) as $name=>$path){if(!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$name)){throw new InvalidArgumentException('resource_schema_name');}self::schemaFile($root,$path,false,true);}
            }
        }
        StaticUi::inspect($root,$manifest);
        HostRequirements::read($root);
        foreach (['composer.json', 'composer.lock'] as $file) {
            JsonDocument::decode(file_get_contents($root.'/'.$file), true);
        }
        return $manifest;
    }

    private static function metadata(mixed $manifest, mixed $object, array $contract): void
    {
        if (!is_array($manifest) || !$object instanceof \stdClass) { throw new InvalidArgumentException('manifest_object_required'); }
        foreach (['runtime','capabilities','support'] as $group) {
            if (!($object->{$group}??null) instanceof \stdClass) { throw new InvalidArgumentException('manifest_group_object'); }
        }
        foreach ([$object->runtime->extensions??null,$object->operations??null,$object->capabilities->required??null,$object->capabilities->optional??null] as $list) {
            if (!is_array($list)) { throw new InvalidArgumentException('manifest_array_required'); }
        }
        foreach (['publisher','integration_key','vendor_family'] as $field) {
            if (!is_string($manifest[$field]??null) || strlen($manifest[$field])>64 || !preg_match('~\A'.$contract['manifest']['identifier_pattern'].'\z~D',$manifest[$field])) { throw new InvalidArgumentException('manifest_identifier'); }
        }
        if (!is_string($manifest['package_id']??null) || strlen($manifest['package_id'])>150 || !preg_match('~\A'.$contract['manifest']['package_id_pattern'].'\z~D',$manifest['package_id'])) { throw new InvalidArgumentException('manifest_identity'); }
        if (!is_string($manifest['version']??null) || strlen($manifest['version'])>64 || !preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*))*)?\z/D',$manifest['version'])) { throw new InvalidArgumentException('manifest_version'); }
        if (!is_string($manifest['display_name']??null) || $manifest['display_name']!==trim($manifest['display_name']) || preg_match_all('/./us',$manifest['display_name'])>120 || !preg_match('/\A[^<>\x00-\x1f\x7f]+\z/uD',$manifest['display_name'])) { throw new InvalidArgumentException('manifest_display_name'); }
        if (in_array($manifest['integration_key'],$contract['manifest']['reserved_integration_keys'],true) || array_any($contract['manifest']['reserved_integration_key_prefixes'],static fn(string $prefix):bool=>str_starts_with($manifest['integration_key'],$prefix))) { throw new InvalidArgumentException('manifest_reserved_integration_key'); }
        if (($manifest['package_id']==='uniscript/lifefile'||$manifest['integration_key']==='lifefile-v2') && ($manifest['package_id']!=='uniscript/lifefile'||$manifest['integration_key']!=='lifefile-v2'||$manifest['display_name']!=='LifeFile v2'||$manifest['vendor_family']!=='lifefile')) { throw new InvalidArgumentException('manifest_reserved_identity'); }
        if (array_diff(array_keys($manifest['runtime']),['entrypoint','php','extensions','profile'])!==[] || !self::list($manifest['runtime']['extensions']??null,32) || array_diff($manifest['runtime']['extensions'],$contract['runtime']['extensions'])!==[]) { throw new InvalidArgumentException('manifest_runtime'); }
        if (!is_string($manifest['runtime']['entrypoint']??null) || !str_ends_with($manifest['runtime']['entrypoint'],'.php')) { throw new InvalidArgumentException('manifest_entrypoint'); }
        if (!self::list($manifest['operations']??null,100,false)) { throw new InvalidArgumentException('manifest_operations'); }
        if (array_diff(array_keys($manifest['capabilities']),['required','optional'])!==[] || !self::list($manifest['capabilities']['required']??null,50) || !self::list($manifest['capabilities']['optional']??null,50) || array_diff($manifest['capabilities']['required'],$contract['manifest']['required_capabilities'])!==[] || array_intersect($manifest['capabilities']['required'],$manifest['capabilities']['optional'])!==[]) { throw new InvalidArgumentException('manifest_capabilities'); }
        foreach ($manifest['capabilities']['optional'] as $capability) {
            if (!is_string($capability)||strlen($capability)>100||!preg_match('/\A[a-z][a-z0-9_]*(?:[.:-][a-z0-9_]+)*\z/D',$capability)) { throw new InvalidArgumentException('manifest_capability'); }
        }
        foreach ($manifest['operations'] as $index=>$operation) {
            if (!($object->operations[$index]??null) instanceof \stdClass || array_diff(['key','handler','input_schema','output_schema','required_capabilities'],array_keys($operation))!==[] || array_diff(array_keys($operation),['key','handler','input_schema','output_schema','required_capabilities'])!==[] || !is_string($operation['key']) || strlen($operation['key'])>100 || !is_string($operation['handler']) || strlen($operation['handler'])>240 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*\z/D',$operation['handler']) || !self::list($operation['required_capabilities'],50) || array_diff($operation['required_capabilities'],$manifest['capabilities']['required'])!==[]) { throw new InvalidArgumentException('manifest_operation'); }
            foreach (['input_schema','output_schema'] as $field) {
                if (!is_string($operation[$field]) || !str_ends_with($operation[$field],'.json')) { throw new InvalidArgumentException('manifest_operation_schema'); }
            }
        }
        if (array_diff(array_keys($manifest['support']),['email','documentation'])!==[] || !is_string($manifest['support']['email']??null) || strlen($manifest['support']['email'])>254 || filter_var($manifest['support']['email'],FILTER_VALIDATE_EMAIL)===false || !is_string($manifest['support']['documentation']??null) || strlen($manifest['support']['documentation'])>500 || filter_var($manifest['support']['documentation'],FILTER_VALIDATE_URL)===false || parse_url($manifest['support']['documentation'],PHP_URL_SCHEME)!=='https') { throw new InvalidArgumentException('manifest_support'); }
    }

    private static function list(mixed $value, int $maximum, bool $unique=true): bool
    {
        return is_array($value) && array_is_list($value) && count($value)<=$maximum && (!$unique || (array_all($value,static fn(mixed $entry):bool=>is_string($entry)) && count(array_unique($value,SORT_REGULAR))===count($value)));
    }

    private static function schemaFile(string $root,mixed $path,bool $credentials,bool $resource=false):void
    {
        if(!is_string($path)||!str_ends_with($path,'.json')){throw new InvalidArgumentException('configuration_schema_path');}
        self::path($path);$file=realpath($root.'/'.$path);$base=realpath($root);
        if($file===false||$base===false||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||is_link($root.'/'.$path)||!is_file($file)||filesize($file)>262144){throw new InvalidArgumentException('configuration_schema_file');}
        $definition=JsonDocument::decode(file_get_contents($file));
        if($resource){ResourceSchema::assertDefinition($definition);}else{ConfigurationSchema::assertDefinition($definition,$credentials);}
    }

    public static function path(string $path): void
    {
        if (strlen($path) > 240 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*\z/D', $path)) { throw new InvalidArgumentException('unsafe_path'); }
        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['.', '..'], true) || str_ends_with($part, '.') || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $part)) { throw new InvalidArgumentException('unsafe_path'); }
        }
    }
}
