<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Archive;
use UniScript\Integration\HostContract;
use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

$packageRoot = $argv[1] ?? dirname(__DIR__).'/examples/reference';
$fixturePath = $argv[2] ?? dirname(__DIR__).'/fixtures/reference.json';
$fixture = json_decode(file_get_contents($fixturePath), true, 32, JSON_THROW_ON_ERROR);
$directory = sys_get_temp_dir().'/uniscript-workbench-runtime-'.bin2hex(random_bytes(8));
$workbench = new Workbench($directory);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$package = $workbench->load($packageRoot);
$check($package['manifest']['package_id'] === $fixture['package_id'], 'Fixture must match package');
$connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
$scope = ['connection_id' => $connection['uuid']];
$check($workbench->dispatch('connection.health', $scope)['connection']['health_current'], 'Real isolated health probe');
$check($workbench->dispatch('connection.test', $scope)['connection']['test_current'], 'Real isolated connection test');
$check($workbench->dispatch('connection.activate', $scope)['status'] === 'active', 'Activation with package configuration validation');
$fixtures = $workbench->snapshot()['fixtures'];
$workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $fixtures['catalog_item_id'], 'expected_revision' => 0, 'values' => $fixture['mapping']]);
$workbench->dispatch('override.save', [...$scope, 'sender_id' => $fixtures['sender_id'], 'credentials' => $fixture['credentials']]);
$check($workbench->dispatch('override.test', [...$scope, 'sender_id' => $fixtures['sender_id']])['sender_overrides'][$fixtures['sender_id']]['tested'], 'Real sender override test');
$workbench->dispatch('override.enable', [...$scope, 'sender_id' => $fixtures['sender_id']]);
$submit = [...$scope, 'catalog_item_id' => $fixtures['catalog_item_id'], 'sender_id' => $fixtures['sender_id'], 'idempotency_key' => 'workbench-runtime-'.bin2hex(random_bytes(8)), 'input' => $fixture['order']];
$order = $workbench->dispatch('order.submit', $submit);
$check($order['status'] === 'submitted_to_pos', 'Actual package accepted: '.json_encode($order['result']));
$check($workbench->dispatch('order.submit', $submit)['uuid'] === $order['uuid'], 'Duplicate submit does not invoke package again');
$canonicalActions = $fixture['action_mappings'] ?? throw new RuntimeException('Canonical action fixture required');
foreach (['status' => 'status_mapping', 'shipping' => 'shipping_mapping'] as $name => $type) {
    $choice = $canonicalActions[$name];
    $workbench->dispatch('resource.fixture.save', [...$scope, 'resource_type' => $type, 'resource_key' => $choice['canonical_code'], 'expected_revision' => 0, 'values' => ['vendor_code' => $choice['vendor_code']]]);
}
$statusActionInput = ['order_id' => $order['uuid'], 'operation' => 'orders.status.update', 'expected_revision' => 0, 'idempotency_key' => WorkbenchState::uuid(), 'canonical_code' => $canonicalActions['status']['canonical_code']];
$statusAction = $workbench->dispatch('order.action', $statusActionInput);
$check($statusAction['action']['status'] === 'completed', 'Real canonical mapped status action: '.json_encode($statusAction['result']));
$check($workbench->dispatch('order.action', $statusActionInput)['action']['uuid'] === $statusAction['action']['uuid'], 'Real action idempotency replay returns the original receipt');
$shippingAction = $workbench->dispatch('order.action', ['order_id' => $order['uuid'], 'operation' => 'orders.shipping.update', 'expected_revision' => 1, 'idempotency_key' => WorkbenchState::uuid(), 'canonical_code' => $canonicalActions['shipping']['canonical_code'], 'shipping' => $canonicalActions['shipping']['shipping']]);
$check($shippingAction['action']['status'] === 'completed', 'Real canonical mapped shipping action: '.json_encode($shippingAction['result']));
$check($shippingAction['order']['status'] === 'submitted_to_pos' && $shippingAction['order']['action_revision'] === 2, 'Canonical actions keep the lifecycle authoritative and record separate receipts');
$webhook = $workbench->dispatch('webhook.create', [...$scope, 'username' => 'local-fixture', 'password' => bin2hex(random_bytes(24))]);
$shipped = $workbench->simulateCallback($order['uuid'], 'Shipped');
$check($shipped['status'] === 'processed', 'Actual inbound decoder creates applied shipped proposal: '.json_encode($shipped));
$check($workbench->simulateCallback($order['uuid'], 'Shipped')['receipt_handle'] === $shipped['receipt_handle'], 'Exact replay retains receipt');
$delivered = $workbench->simulateCallback($order['uuid'], 'Delivered');
$check($delivered['status'] === 'processed', 'Actual inbound decoder creates applied delivered proposal');
$latest = $workbench->snapshot()['orders'][0];
$check($latest['status'] === 'completed' && count($latest['fulfillments']) === 1 && $latest['fulfillments'][0]['delivered'], 'One parcel completed through real package proposals');
foreach (['connection.setup', 'catalog.item.mapping', 'pharmacy.order.details', 'sender.order.details'] as $surface) {
    $session = $workbench->openSession($connection['uuid'], $surface, 'runtime-test-browser', 'http://127.0.0.1:19100', $surface === 'catalog.item.mapping' ? $fixtures['catalog_item_id'] : null, str_contains($surface, 'order.details') ? $order['uuid'] : null);
    foreach ($session['queries'] as $operation) {
        $projection = $workbench->bridge($session['session_id'], ['version' => '1', 'id' => 'query-'.str_replace('.', '-', $operation), 'nonce' => $session['nonce'], 'session_id' => $session['session_id'], 'execution_revision' => $session['execution_revision'], 'expected_revision' => $session['expected_revision'], 'kind' => 'query', 'operation' => $operation, 'arguments' => []], 'runtime-test-browser', 'http://127.0.0.1:19100');
        HostContract::assertProjection($operation, $projection);
        $check(true, 'Projection conforms: '.$surface.' '.$operation);
    }
}
$nextSource = $directory.'/next-source';
$archive = $directory.'/runtime/executions/'.$package['execution_revision'].'/source.zip';
Archive::unpack($archive, $nextSource, hash_file('sha256', $archive));
$manifest = json_decode(file_get_contents($nextSource.'/integration.json'), true, 32, JSON_THROW_ON_ERROR);
$version = explode('.', $manifest['version']);
$manifest['version'] = $version[0].'.'.$version[1].'.'.((int) $version[2] + 1);
file_put_contents($nextSource.'/integration.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$nextPackage = $workbench->load($nextSource);
$upgrade = $workbench->dispatch('connection.upgrade', [...$scope, 'execution_revision' => $nextPackage['execution_revision'], 'copy_credentials' => true]);
$check($upgrade['config'] === $fixture['configuration'] && $upgrade['mappings'][$fixtures['catalog_item_id']]['revision'] === 1 && ! $upgrade['test_current'], 'Real runtime upgrade retains data and resets evidence');
$check($upgrade['webhooks'][0]['uuid'] === $webhook['uuid'], 'Stable endpoint across real package upgrade');
$nextScope = ['connection_id' => $upgrade['uuid']];
$workbench->dispatch('connection.health', $nextScope);
$workbench->dispatch('connection.test', $nextScope);
$workbench->dispatch('connection.activate', $nextScope);
$workbench->dispatch('override.test', [...$nextScope, 'sender_id' => $fixtures['sender_id']]);
$workbench->dispatch('override.enable', [...$nextScope, 'sender_id' => $fixtures['sender_id']]);
$nextOrder = $workbench->dispatch('order.submit', [...$submit, 'connection_id' => $upgrade['uuid'], 'idempotency_key' => $submit['idempotency_key'].'-next']);
$check($nextOrder['status'] === 'submitted_to_pos' && $nextOrder['execution_revision'] === $nextPackage['execution_revision'], 'New order uses upgraded package');
$check($workbench->simulateCallback($nextOrder['uuid'], 'Shipped')['status'] === 'processed' && $workbench->simulateCallback($nextOrder['uuid'], 'Delivered')['status'] === 'processed', 'New order decodes under new execution');
$check($workbench->simulateCallback($order['uuid'], 'Delivered')['duplicate'], 'Original order callback still accepted through original receipt');
$oldSession = $workbench->openSession($connection['uuid'], 'sender.order.details', 'runtime-test-browser', 'http://127.0.0.1:19100', null, $order['uuid']);
$check($oldSession['execution_revision'] === $package['execution_revision'], 'Original order keeps its original UI artifact after activation');
echo json_encode(['package_id' => $fixture['package_id'], 'checks' => $checks, 'result' => 'passed', 'state' => $directory, 'webhook' => $webhook['path'], 'mode' => 'real isolated package runtime, offline synthetic vendor/host'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
