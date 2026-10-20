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
        $keys = ['manifest_version', 'package_id', 'publisher', 'integration_key', 'display_name', 'vendor_family', 'version', 'sdk_version', 'protocol_version', 'runtime', 'operations', 'capabilities', 'support'];
        $version=$manifest['manifest_version']??null;
        $required=$keys;
        if($version===2){$required=[...$required,'configuration_schema','credential_schema'];$keys=[...$keys,'configuration_schema','credential_schema','resource_schemas'];}
        if (!is_array($manifest) || array_diff($required, array_keys($manifest)) !== [] || array_diff(array_keys($manifest), $keys) !== []) { throw new InvalidArgumentException('manifest_fields'); }
        if (!in_array($manifest['manifest_version'],[1,2],true) || $manifest['protocol_version'] !== '1' || $manifest['sdk_version'] !== '^1.0' || !preg_match('/\A[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*\z/D', $manifest['package_id']) || !str_starts_with($manifest['package_id'], $manifest['publisher'].'/') || !preg_match('/\A\d+\.\d+\.\d+\z/D', $manifest['version'])) { throw new InvalidArgumentException('manifest_identity'); }
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
        foreach (['composer.json', 'composer.lock'] as $file) {
            JsonDocument::decode(file_get_contents($root.'/'.$file), true);
        }
        return $manifest;
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
