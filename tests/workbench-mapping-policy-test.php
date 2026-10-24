<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\Manifest;
use UniScript\Integration\MappingSchemaPolicy;
use UniScript\Integration\ResourceSchema;

$root = sys_get_temp_dir().'/mapping-policy-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    foreach ([['api_key', [], false], ['client_secret', [], false], ['approval_status', [], false], ['tenant_id', ['tenant_id'], false], ['mapped_id', ['api_key'], true], ['vendor_product_key', [], true]] as [$field, $credentials, $valid]) {
        $schema = (object) ['type' => 'object', 'properties' => (object) [$field => (object) ['type' => 'string', 'maxLength' => 100]], 'required' => [], 'additionalProperties' => false];
        ResourceSchema::assertDefinition($schema);
        foreach (['order_mapping', 'status_mapping', 'shipping_mapping', 'reference'] as $resource) {
            try {
                MappingSchemaPolicy::assertSafe($schema, $credentials, $resource);
                $accepted = true;
            } catch (InvalidArgumentException $exception) {
                $accepted = false;
                workbenchCheck(str_contains($exception->getMessage(), $resource.'.'.$field) && str_contains($exception->getMessage(), 'credential_schema'), 'Mapping rejection explains the field and secret controls');
            }
            workbenchCheck($accepted === ($valid || $resource === 'reference'), 'Host mapping policy is scoped to mapped resources');
        }
    }
    workbenchCopy(dirname(__DIR__).'/examples/reference', $root.'/source');
    $path = $root.'/source/schemas/order_mapping.json';
    $schema = json_decode(file_get_contents($path));
    $schema->properties->api_key = (object) ['type' => 'string', 'maxLength' => 100];
    file_put_contents($path, json_encode($schema, JSON_THROW_ON_ERROR));
    try {
        Manifest::read($root.'/source');
        throw new RuntimeException('Unsafe mapping schema exported');
    } catch (InvalidArgumentException $exception) {
        workbenchCheck(str_contains($exception->getMessage(), 'order_mapping.api_key'), 'Local manifest validation rejects unsafe mapping before export/build');
    }
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
