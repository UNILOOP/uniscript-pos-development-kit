<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\DevelopmentRuntime;
use UniScript\Integration\JsonDocument;
use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$root = dirname(__DIR__);
$state = sys_get_temp_dir().'/uniscript-development-runtime-'.bin2hex(random_bytes(6));
$runtime = new DevelopmentRuntime($state);
$defaults = JsonDocument::decode(file_get_contents($root.'/fixtures/defaults.json'), true);
$checks = 0;
function check(bool $value, string $reason): void
{
    global $checks;
    $checks++;
    if (! $value) {
        throw new RuntimeException($reason);
    }
}
$deployment = $runtime->prepare($root.'/examples/reference', 'development-reference-'.bin2hex(random_bytes(8)));
check($deployment['status'] === 'development_ready' && $deployment['certified'] === false, 'development_is_not_final_certification');
check($runtime->prepare($root.'/examples/reference', $deployment['execution_revision']) === $deployment, 'execution_is_immutable');
$invoke = static fn (string $operation, array $input = [], array $scope = []): array => $runtime->invoke($deployment, $operation, $input, $defaults['configuration'], $defaults['credentials'], ['catalog' => $defaults['catalog']], $scope);
check($invoke('identity.describe')['data']['package_id'] === 'uniscript/reference-pos', 'real_docker_identity');
check($invoke('configuration.validate')['kind'] === 'success', 'configuration');
check($invoke('health.probe')['kind'] === 'success', 'health');
check($invoke('connection.test')['kind'] === 'success', 'connection_test');
$order = DevelopmentRuntime::orderFixture($defaults['mapping']);
check($invoke('orders.validate', $order)['kind'] === 'success', 'canonical_order');
$accepted = $invoke('orders.submit', $order, ['idempotency_key' => 'one-order']);
check($accepted['kind'] === 'remote_acceptance', 'submit');
$reference = $runtime->lastEvidence()['external_references'][0];
check($invoke('orders.submit', $order, ['idempotency_key' => 'one-order']) === $accepted, 'duplicate_submit');
check($invoke('orders.submit', [...$order, 'scenario' => 'accepted_then_lost'], ['idempotency_key' => 'unknown-order'])['kind'] === 'unknown_remote_outcome', 'unknown_acceptance');
check($invoke('orders.reconcile', $order, ['idempotency_key' => 'unknown-order'])['kind'] === 'remote_acceptance', 'reconcile_without_resubmit');
check($invoke('orders.status.update', ['external_reference_handle' => $reference['handle'], 'status' => 'in_progress'])['kind'] === 'remote_acceptance', 'status_action');
check($invoke('orders.shipping.update', ['external_reference_handle' => $reference['handle'], 'shipping' => ['service' => 'ground']])['kind'] === 'remote_acceptance', 'shipping_action');
check($invoke('catalog.preview')['data']['proposals'][0]['mapping_values'] === $defaults['mapping'], 'catalog_resource_read');
check($invoke('reference.refresh')['data']['revision'] === 1, 'typed_reference_write');
check($invoke('reference.inspect')['data']['record']['revision'] === 1, 'typed_reference_read');
$event = ['orderId' => $reference['reference'], 'eventId' => 'event-shipped', 'status' => 'shipped', 'trackingNumber' => 'SYNTHETIC-TRACKING', 'carrier' => 'Synthetic carrier'];
check($invoke('inbound.decode', ['authenticated_receipt' => true, 'receipt_handle' => 'receipt-shipped', 'events' => [$event]])['kind'] === 'success', 'inbound_decoder');
check($runtime->lastEvidence()['proposals'][0]['event']['order_status'] === 'ready', 'trusted_proposal_evidence');
check($invoke('inbound.decode', ['authenticated_receipt' => false, 'events' => [$event]])['kind'] === 'validation_rejection', 'unauthenticated_decode_denied');
check($invoke('undefined.operation')['kind'] === 'unsupported_operation', 'unknown_operation');
check($invoke('orders.submit', [...$order, 'order' => [...$order['order'], 'medications' => [[...$order['order']['medications'][0], 'mapping' => ['schedule_code' => '2']]]]])['kind'] === 'validation_rejection', 'controlled_fixture_blocked');
check($invoke('health.probe', ['scenario' => 'rate_limited'])['kind'] === 'temporary_failure', 'rate_limit');
check($invoke('orders.submit', [...$order, 'scenario' => 'malformed_response'], ['idempotency_key' => 'malformed-order'])['kind'] === 'unknown_remote_outcome', 'malformed_remote_response');
check($runtime->lastEvidence()['network_access'] === false, 'offline_vendor_simulator');
check(Process::run(['docker', 'ps', '-a', '-q', '--filter', 'name=^/uniscript-invocation-']) === '', 'no_invocation_containers_left');
$platform = new Platform($state.'/platform');
$certification = $platform->enqueue('certifications', ['execution_uuid' => $deployment['execution_revision'], 'build_id' => $deployment['build_id'], 'source_sha256' => $deployment['source_sha256'], 'digests' => $deployment['digests']]);
$platform->work();
$certification = $platform->read('certifications', $certification['id']);
check($certification['status'] === 'passed', 'independent_reviewed_reference_conformance:'.($certification['_diagnostic'] ?? ''));
check(count($certification['certification_summary']['operations']) === 13, 'all_reference_operations_certified');
$release = $platform->enqueue('deployments', ['deployment_uuid' => 'reference-deployment-'.bin2hex(random_bytes(8)), 'execution_uuid' => $deployment['execution_revision'], 'certification_id' => $certification['id'], 'environment' => 'local', 'digests' => $deployment['digests']]);
$platform->work();
$release = $platform->read('deployments', $release['id']);
try {
    check($release['status'] === 'ready', 'reviewed_reference_deployment:'.($release['_diagnostic'] ?? ''));
} finally {
    if (isset($release['_container'])) {
        Process::run(['docker', 'rm', '-f', $release['_container']], 30);
    }
}
$profiles = ['synthetic.health' => ['operations' => ['health.probe'], 'mode' => 'read', 'response' => ['package_marker' => 'reference']]];
$otherProfiles = ['synthetic.health' => ['operations' => ['health.probe'], 'mode' => 'read', 'response' => ['package_marker' => 'other-vendor']]];
$scopedRuntime = new DevelopmentRuntime($state, ['package_profiles' => ['uniscript/reference-pos' => $profiles, 'acme/other-pos' => $otherProfiles]]);
$scopedResult = $scopedRuntime->invoke($deployment, 'health.probe', [], $defaults['configuration'], $defaults['credentials']);
check($scopedResult['data']['response']['package_marker'] === 'reference', 'Fixture profile selection is scoped to the immutable package identity');
$changedOther = new DevelopmentRuntime($state, ['package_profiles' => ['uniscript/reference-pos' => $profiles, 'acme/other-pos' => []]]);
check($scopedRuntime->transportBinding('uniscript/reference-pos') === $changedOther->transportBinding('uniscript/reference-pos'), 'Another package fixture change does not invalidate this connection evidence');
$missingFixture = new DevelopmentRuntime($state, ['package_profiles' => ['acme/other-pos' => $otherProfiles]]);
check($missingFixture->invoke($deployment, 'health.probe', [], $defaults['configuration'], $defaults['credentials'])['kind'] === 'temporary_failure', 'Missing package fixture cannot borrow another package or global default profile');
echo json_encode(['result' => 'passed', 'assertions' => $checks, 'execution_revision' => $deployment['execution_revision'], 'source_sha256' => $deployment['source_sha256'], 'simulated_vendor' => true], JSON_THROW_ON_ERROR)."\n";
