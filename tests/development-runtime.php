<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Archive;
use UniScript\Integration\DevelopmentServices;
use UniScript\Integration\HostContract;
use UniScript\Integration\JsonDocument;
use UniScript\Integration\Manifest;
use UniScript\Integration\Process;
use UniScript\Integration\Result;

$root = dirname(__DIR__);
$state = sys_get_temp_dir().'/uniscript-development-services-'.bin2hex(random_bytes(6));
mkdir($state, 0700, true);
$manifest = Manifest::read($root.'/examples/reference');
$deployment = ['manifest' => $manifest, 'package_root' => $root.'/examples/reference'];
$checks = 0;
function check(bool $value, string $reason): void
{
    global $checks;
    $checks++;
    if (! $value) {
        throw new RuntimeException($reason);
    }
}
function envelope(string $operation, string $key = 'one-order', array $input = [], string $connection = 'connection-one'): array
{
    return ['protocol_version' => '1', 'invocation_id' => bin2hex(random_bytes(16)), 'command_id' => 'command', 'attempt_id' => 'attempt', 'correlation_id' => 'correlation', 'package_id' => 'uniscript/reference-pos', 'execution_revision' => 'execution-one', 'connection_id' => $connection, 'organization_id' => 'organization-one', 'location_id' => 'location-one', 'environment' => 'sandbox', 'deployment_environment' => 'local', 'actor_id' => 'pharmacy-one', 'operation' => $operation, 'schema_version' => '1', 'routing_revision' => '1', 'configuration_revision' => '1', 'mapping_revision' => '1', 'credential_revision' => '1', 'idempotency_key' => $key, 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 30), 'request_budget' => 100, 'input' => $input, 'service_grant' => bin2hex(random_bytes(32))];
}
function call(DevelopmentServices $broker, array $envelope, string $service, string $action, array $arguments): array
{
    return $broker->request(['invocation_id' => $envelope['invocation_id'], 'grant' => $envelope['service_grant'], 'service' => $service, 'action' => $action, 'arguments' => $arguments]);
}
function denied(Closure $callback, string $reason): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        check(true, $reason);

        return;
    }
    check(false, $reason);
}
$defaults = JsonDocument::decode(file_get_contents($root.'/fixtures/defaults.json'), true);
$env = envelope('orders.submit');
$broker = new DevelopmentServices($state.'/services.sqlite', $env, $deployment, $defaults['configuration'], $defaults['credentials']);
$request = ['profile' => 'synthetic.orders', 'operation_key' => 'orders.submit', 'parameters' => [], 'idempotency_key' => 'one-order', 'body' => ['order_id' => 'one-order']];
$accepted = call($broker, $env, 'http', 'request', $request);
$duplicate = call($broker, $env, 'http', 'request', $request);
check($accepted === $duplicate && $accepted['status'] === 'accepted', 'duplicate_transport_has_one_reference');
check(array_keys($accepted) === ['status', 'external_reference_handle'], 'generic_managed_acceptance_matches_opaque_host_contract');
check($broker->evidence()['external_references'][0]['reference'] !== $accepted['external_reference_handle'], 'opaque_reference_handle');
check(! str_contains(json_encode($accepted), 'local-development-only'), 'credentials_never_in_response');
denied(fn () => call($broker, $env, 'http', 'request', [...$request, 'url' => 'https://example.com']), 'arbitrary_network_denied');
denied(fn () => call($broker, $env, 'http', 'request', [...$request, 'body' => ['order_id' => 'different']]), 'idempotency_body_conflict_denied');
denied(fn () => call($broker, $env, 'http', 'request', [...$request, 'profile' => 'unknown.vendor']), 'unknown_profile_denied');
denied(fn () => call($broker, $env, 'storage', 'write', ['key' => 'reference', 'value' => [], 'expected_revision' => 0]), 'operation_service_denied');
denied(fn () => $broker->request(['invocation_id' => $env['invocation_id'], 'grant' => 'wrong', 'service' => 'http', 'action' => 'request', 'arguments' => $request]), 'bad_grant_denied');

$unknownEnv = envelope('orders.submit', 'unknown-order', ['scenario' => 'accepted_then_lost']);
$unknownBroker = new DevelopmentServices($state.'/services.sqlite', $unknownEnv, $deployment, $defaults['configuration'], $defaults['credentials']);
$unknown = call($unknownBroker, $unknownEnv, 'http', 'request', [...$request, 'idempotency_key' => 'unknown-order']);
check($unknown['status'] === 'unknown', 'accepted_response_lost');
$reconcileEnv = envelope('orders.reconcile', 'unknown-order');
$reconcileBroker = new DevelopmentServices($state.'/services.sqlite', $reconcileEnv, $deployment, $defaults['configuration'], $defaults['credentials']);
$reconciled = call($reconcileBroker, $reconcileEnv, 'http', 'request', ['profile' => 'synthetic.reconcile', 'operation_key' => 'orders.reconcile', 'parameters' => [], 'idempotency_key' => 'unknown-order']);
check($reconciled['status'] === 'accepted', 'reconcile_durable_acceptance');
$otherEnv = envelope('orders.reconcile', 'unknown-order', [], 'connection-two');
$otherBroker = new DevelopmentServices($state.'/services.sqlite', $otherEnv, $deployment, $defaults['configuration'], $defaults['credentials']);
check(call($otherBroker, $otherEnv, 'http', 'request', ['profile' => 'synthetic.reconcile', 'operation_key' => 'orders.reconcile', 'parameters' => [], 'idempotency_key' => 'unknown-order'])['status'] === 'unknown', 'other_connection_has_no_reference');
foreach (['credential_rejected' => 401, 'remote_rejected' => 422, 'rate_limited' => 429] as $scenario => $status) {
    $scenarioEnv = envelope('health.probe', input: ['scenario' => $scenario]);
    $scenarioBroker = new DevelopmentServices($state.'/services.sqlite', $scenarioEnv, $deployment, $defaults['configuration'], $defaults['credentials']);
    check(call($scenarioBroker, $scenarioEnv, 'http', 'request', ['profile' => 'synthetic.health', 'operation_key' => 'health.probe', 'parameters' => [], 'idempotency_key' => 'one-order']) === ['status' => 'rejected', 'reason_code' => 'managed_read_rejected'], $scenario);
}
$missingCredentialsEnv = envelope('health.probe');
$missingCredentials = new DevelopmentServices($state.'/services.sqlite', $missingCredentialsEnv, $deployment);
check(call($missingCredentials, $missingCredentialsEnv, 'http', 'request', ['profile' => 'synthetic.health', 'operation_key' => 'health.probe', 'parameters' => [], 'idempotency_key' => 'one-order']) === ['status' => 'rejected', 'reason_code' => 'managed_read_rejected'], 'missing_host_credentials_denied');

$writeEnv = envelope('reference.refresh');
$writeBroker = new DevelopmentServices($state.'/services.sqlite', $writeEnv, $deployment);
$written = call($writeBroker, $writeEnv, 'storage', 'write', ['key' => 'reference', 'value' => ['values' => ['ground']], 'expected_revision' => 0]);
check($written['revision'] === 1, 'typed_resource_written');
denied(fn () => call($writeBroker, $writeEnv, 'storage', 'write', ['key' => 'reference', 'value' => ['values' => ['overnight']], 'expected_revision' => 0]), 'stale_storage_denied');
denied(fn () => call($writeBroker, $writeEnv, 'storage', 'read', ['key' => 'undeclared']), 'undeclared_storage_denied');
$readEnv = envelope('reference.inspect');
$readBroker = new DevelopmentServices($state.'/services.sqlite', $readEnv, $deployment);
check(call($readBroker, $readEnv, 'storage', 'read', ['key' => 'reference'])['record']['value']['values'] === ['ground'], 'resources_persist_between_invocations');

$receiptEnv = envelope('inbound.decode', input: ['authenticated_receipt' => true, 'receipt_handle' => 'receipt-one']);
$receiptBroker = new DevelopmentServices($state.'/services.sqlite', $receiptEnv, $deployment);
$proposal = call($receiptBroker, $receiptEnv, 'commands', 'propose', ['command' => 'normalized_event', 'receipt_handle' => 'receipt-one', 'event' => ['external_order_reference' => 'local-test', 'event_id' => 'event-one', 'order_status' => 'ready']]);
check($proposal['applied'] === false && count($receiptBroker->evidence()['proposals']) === 1, 'package_proposes_but_does_not_apply');
denied(fn () => call($receiptBroker, $receiptEnv, 'commands', 'propose', ['command' => 'normalized_event', 'receipt_handle' => 'other', 'event' => []]), 'receipt_scope_denied');

check($proposal['receipt_verified'] === true && $proposal['status'] === 'pending_review', 'proposal_response_matches_real_host');
foreach (JsonDocument::decode(file_get_contents($root.'/contracts/normalized-event-examples.json'), true) as $case) {
    if ($case['valid']) {
        continue;
    }
    try {
        call($receiptBroker, $receiptEnv, 'commands', 'propose', $case['arguments']);
        check(false, 'broker_rejects_'.$case['name']);
    } catch (InvalidArgumentException) {
        check(true, 'broker_rejects_'.$case['name']);
    }
}
check(count($receiptBroker->evidence()['proposals']) === 1, 'invalid_proposals_never_recorded');

Process::run([PHP_BINARY, $root.'/bin/package.php', $root.'/examples/reference', $state.'/reference.zip']);
check(Archive::unpack($state.'/reference.zip', $state.'/unpacked', hash_file('sha256', $state.'/reference.zip'))['package_id'] === 'uniscript/reference-pos', 'reference_zip_inspected');
check($manifest['ui']['surfaces'] === ['connection.setup', 'catalog.item.mapping', 'pharmacy.order.details', 'sender.order.details'], 'reference_all_four_surfaces');
check(HostContract::read()['runtime']['result_kinds'] === Result::KINDS, 'result_kinds_match_real_host');
try {
    new Result('partial', 'unsupported.partial');
    check(false, 'partial_must_not_be_advertised');
} catch (InvalidArgumentException) {
    check(true, 'partial_must_not_be_advertised');
}
$candidate = $state.'/unpacked';
$original = file_get_contents($candidate.'/integration.json');
foreach ([
    ['publisher' => 'uniloop--invalid', 'package_id' => 'uniloop--invalid/reference-pos'],
    ['version' => '01.0.0'],
    ['version' => '1.0.0+unaccepted-build-metadata'],
    ['display_name' => ' Whitespace '],
    ['display_name' => '<script>Unsafe</script>'],
    ['integration_key' => 'package'],
    ['runtime' => [...$manifest['runtime'], 'unknown' => true]],
    ['runtime' => [...$manifest['runtime'], 'extensions' => ['unsupported-extension']]],
    ['capabilities' => ['required' => ['unknown'], 'optional' => []]],
    ['support' => ['email' => 'invalid', 'documentation' => 'http://unsafe.example']],
] as $mutation) {
    file_put_contents($candidate.'/integration.json', json_encode([...$manifest, ...$mutation], JSON_THROW_ON_ERROR));
    try {
        Manifest::read($candidate);
        check(false, 'invalid_host_metadata_rejected');
    } catch (InvalidArgumentException) {
        check(true, 'invalid_host_metadata_rejected');
    }
}
file_put_contents($candidate.'/integration.json', json_encode([...$manifest, 'version' => '1.0.0-beta.1'], JSON_THROW_ON_ERROR));
check(Manifest::read($candidate)['version'] === '1.0.0-beta.1', 'host_supported_prerelease_accepted');
file_put_contents($candidate.'/integration.json', $original);
echo json_encode(['result' => 'passed', 'assertions' => $checks, 'network_requests' => 0, 'simulated' => true], JSON_THROW_ON_ERROR)."\n";
