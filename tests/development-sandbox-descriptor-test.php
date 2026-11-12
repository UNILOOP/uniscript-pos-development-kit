<?php

declare(strict_types=1);

define('SANDBOX_FIXTURES_ONLY', true);
require __DIR__.'/development-sandbox-test.php';

use UniScript\Integration\DevelopmentSandboxTransport;

$root = sys_get_temp_dir().'/pos-sandbox-descriptors-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$calls = 0;
$sender = static function () use (&$calls): array {
    $calls++;

    return ['status' => 200, 'body' => '{}'];
};
$cases = [
    'unknown transport field' => [static fn (array $p): array => [...$p, 'skip_authorization' => true], 'sandbox_profile_fields_unsupported'],
    'unshared local limit' => [static fn (array $p): array => [...$p, 'max_response_bytes' => 42], 'sandbox_profile_fields_unsupported'],
    'unknown auth field' => [static fn (array $p): array => [...$p, 'authentication' => [...$p['authentication'], 'raw_secret' => 'must-not-be-used']], 'sandbox_authentication_fields_unsupported'],
    'duplicate header case' => [static fn (array $p): array => [...$p, 'config_headers' => ['X-Account' => 'account_id', 'x-account' => 'account_id']], 'sandbox_configuration_header_collision'],
    'host verification header' => [static fn (array $p): array => [...$p, 'config_headers' => ['X-UniScript-Challenge' => 'account_id']], 'sandbox_configuration_header_binding'],
    'host timezone header' => [static fn (array $p): array => [...$p, 'config_headers' => ['X-Timezone-Name' => 'account_id']], 'sandbox_configuration_header_binding'],
    'undefined numeric identity' => [static fn (array $p): array => [...$p, 'numeric_account_fields' => ['missing_account']], 'sandbox_numeric_account_binding_invalid'],
    'ambiguous body validator' => [static fn (array $p): array => [...$p, 'body_rules' => ['body' => ['array']]], 'sandbox_body_validator_ambiguous'],
    'invalid wire schema' => [static fn (array $p): array => [...$p, 'body_schema' => [...$p['body_schema'], 'oneOf' => []]], 'sandbox_body_schema_unsupported'],
    'invalid body account binding' => [static fn (array $p): array => [...$p, 'body_account_bindings' => ['account_id' => true]], 'sandbox_body_account_binding_invalid'],
    'unknown invocation projection field' => [static fn (array $p): array => [...$p, 'body_invocation_projection' => ['update' => ['source' => 'parameters', 'extra' => true]]], 'sandbox_invocation_projection_invalid'],
    'invalid invocation projection path' => [static fn (array $p): array => [...$p, 'body_invocation_projection' => ['update' => ['source' => 'parameters', 'field_map' => ['carrier' => '../outside']]]], 'sandbox_invocation_projection_invalid'],
    'secret configuration account' => [static fn (array $p): array => [...$p, 'account_fields' => ['account' => 'api_token']], 'sandbox_account_binding_invalid'],
    'query in the path' => [static fn (array $p): array => [...$p, 'path' => '/orders?n=API'], 'sandbox_fixed_endpoint_required'],
    'query list' => [static fn (array $p): array => [...$p, 'query' => ['API']], 'sandbox_query_invalid'],
    'query non-string value' => [static fn (array $p): array => [...$p, 'query' => ['n' => 1]], 'sandbox_query_invalid'],
    'query header injection' => [static fn (array $p): array => [...$p, 'query' => ['n' => "API\r\nHost: other"]], 'sandbox_query_invalid'],
    'query unsafe name' => [static fn (array $p): array => [...$p, 'query' => ['n&x' => 'API']], 'sandbox_query_invalid'],
    'query oversized value' => [static fn (array $p): array => [...$p, 'query' => ['n' => str_repeat('a', 2049)]], 'sandbox_query_invalid'],
    'wildcard response path' => [static fn (array $p): array => [...$p, 'reference_path' => 'orders.*.id'], 'sandbox_response_binding_invalid'],
    'unbounded list position' => [static fn (array $p): array => [...$p, 'reference_path' => 'orders.1000.id'], 'sandbox_response_binding_invalid'],
    'unknown success policy' => [static fn (array $p): array => [...$p, 'success_policy' => 'always'], 'sandbox_success_policy_unsupported'],
    'document binding on a read' => [static fn (array $p): array => [...$p, 'method' => 'GET', 'body_document_bindings' => ['pdfScript' => ['document' => 'prescription_pdf', 'required' => true]]], 'sandbox_document_binding_invalid'],
    'unknown bound document' => [static fn (array $p): array => [...$p, 'body_document_bindings' => ['pdfScript' => ['document' => 'insurance_card', 'required' => true]]], 'sandbox_document_binding_invalid'],
    'document binding without required flag' => [static fn (array $p): array => [...$p, 'body_document_bindings' => ['pdfScript' => ['document' => 'prescription_pdf']]], 'sandbox_document_binding_invalid'],
    'unknown path substitution' => [static fn (array $p): array => [...$p, 'path' => '/orders/{untrusted_account}'], 'sandbox_fixed_endpoint_required'],
];
try {
    foreach ($cases as $name => [$change, $reason]) {
        $transport = new DevelopmentSandboxTransport($root.'/'.md5($name).'.sqlite', ['enabled' => true, 'package_id' => 'acme/test-pos', 'profiles' => ['test.vendor' => $change(sandboxProfile())]], static fn (): array => ['8.8.8.8'], $sender);
        sandboxDenied(fn () => $transport->profile('test.vendor', 'orders.submit'), $reason);
    }
    sandboxCheck($calls === 0, 'Malformed policy fails before any HTTP request');
    $requirements = json_decode(file_get_contents(__DIR__.'/fixtures/release-host-requirements.json'), true, 32, JSON_THROW_ON_ERROR);
    $owner = $requirements['package_id'];
    $default = UniScript\Integration\PayloadLimits::serviceRequestForPackage($owner);
    $requirements['definitions']['limits'] = ['service_request_bytes' => 524288];
    UniScript\Integration\HostRequirements::parse(json_encode($requirements, JSON_THROW_ON_ERROR));
    sandboxCheck($default === 131072 && UniScript\Integration\PayloadLimits::serviceRequestForPackage($owner) === 524288 && UniScript\Integration\PayloadLimits::forPackage($owner) === 1048576, 'A release declares only the payload budgets it changes');
    sandboxCheck(UniScript\Integration\PayloadLimits::serviceRequestForPackage('other/package') === 131072, 'A declared budget applies only to its own package');
    foreach ([['response_bytes' => 12582913], ['invocation_bytes' => 512], ['archive_bytes' => 4096], ['response_bytes' => '4096'], []] as $limits) {
        sandboxDenied(fn () => UniScript\Integration\HostRequirements::parse(json_encode([...$requirements, 'definitions' => [...$requirements['definitions'], 'limits' => $limits]], JSON_THROW_ON_ERROR)), 'host_requirements_limits');
    }
    echo 'Strict shared Sandbox descriptors: '.$sandboxChecks." checks passed.\n";
} finally {
    sandboxRemove($root);
}
