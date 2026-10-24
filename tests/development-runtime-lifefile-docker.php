<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\DevelopmentRuntime;
use UniScript\Integration\JsonDocument;

$root = dirname(__DIR__);
$package = getenv('UNISCRIPT_LIFEFILE_PACKAGE') ?: throw new RuntimeException('Set UNISCRIPT_LIFEFILE_PACKAGE to the separately checked out LifeFile v2 source.');
$fixture = JsonDocument::decode(file_get_contents($root.'/fixtures/lifefile-v2.json'), true);
$runtime = new DevelopmentRuntime(sys_get_temp_dir().'/uniscript-development-lifefile-'.bin2hex(random_bytes(6)), ['profiles' => $fixture['profiles']]);
$deployment = $runtime->prepare($package, 'development-lifefile-'.bin2hex(random_bytes(8)));
$checks = 0;
function check(bool $value, string $reason): void
{
    global $checks;
    $checks++;
    if (! $value) {
        throw new RuntimeException($reason);
    }
}
$invoke = static fn (string $operation, array $input = [], array $scope = []): array => $runtime->invoke($deployment, $operation, $input, $fixture['configuration'], $fixture['credentials'], [], $scope);
check($invoke('identity.describe')['data']['package_id'] === $fixture['package_id'], 'same_adapter_different_vendor');
check($invoke('configuration.validate')['kind'] === 'success', 'configuration');
check($invoke('health.probe')['kind'] === 'success', 'health');
check($invoke('connection.test')['kind'] === 'success', 'connection_test');
$order = $fixture['order'];
$valid = $invoke('orders.validate', $order);
check($valid['kind'] === 'success', 'canonical_order:'.json_encode($valid['field_errors']));
$accepted = $invoke('orders.submit', $order, ['idempotency_key' => 'same-lifefile-order']);
check($accepted['kind'] === 'remote_acceptance', 'order_submit:'.json_encode($accepted));
$reference = $runtime->lastEvidence()['external_references'][0];
check($invoke('orders.submit', $order, ['idempotency_key' => 'same-lifefile-order']) === $accepted, 'duplicate_submit');
check($invoke('orders.reconcile', ['external_reference_handle' => $reference['handle']], ['idempotency_key' => 'same-lifefile-order'])['kind'] === 'remote_acceptance', 'reconcile');
$event = ['eventId' => 'synthetic-lifefile-shipped', 'orderId' => $reference['reference'], 'practiceId' => $fixture['configuration']['practice_id'], 'rxStatus' => 'Shipped', 'trackingNumber' => 'SYNTHETIC-TRACKING', 'shipCarrier' => 'Synthetic carrier'];
check($invoke('inbound.decode', ['authenticated_receipt' => true, 'receipt_handle' => 'lifefile-receipt', 'events' => [$event]])['kind'] === 'success', 'callback');
check($runtime->lastEvidence()['proposals'][0]['event']['order_status'] === 'ready', 'canonical_callback');
check($runtime->lastEvidence()['network_access'] === false, 'no_live_lifefile_requests');
echo json_encode(['result' => 'passed', 'assertions' => $checks, 'source_sha256' => $deployment['source_sha256'], 'simulated_vendor' => true], JSON_THROW_ON_ERROR)."\n";
