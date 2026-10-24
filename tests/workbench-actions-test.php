<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

$root = sys_get_temp_dir().'/workbench-actions-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$runtime = new WorkbenchFixtureRuntime($root.'/builds');
$workbench = new Workbench($root.'/state', $runtime);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
try {
    $package = $workbench->load(dirname(__DIR__).'/examples/reference');
    $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $scope = ['connection_id' => $connection['uuid']];
    $ids = $workbench->snapshot()['fixtures'];
    $workbench->dispatch('connection.health', $scope);
    $workbench->dispatch('connection.test', $scope);
    $workbench->dispatch('connection.activate', $scope);
    $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 0, 'values' => $fixture['mapping']]);
    foreach (['status_mapping' => ['received', 'in_progress'], 'shipping_mapping' => ['ground', 'GROUND-STD']] as $type => [$canonical, $vendor]) {
        $workbench->dispatch('resource.fixture.save', [...$scope, 'resource_type' => $type, 'resource_key' => $canonical, 'expected_revision' => 0, 'values' => ['vendor_code' => $vendor]]);
    }
    $order = $workbench->dispatch('order.submit', [...$scope, 'sender_id' => $ids['sender_id'], 'catalog_item_id' => $ids['catalog_item_id'], 'idempotency_key' => 'canonical-actions', 'input' => $fixture['order']]);
    $action = ['order_id' => $order['uuid'], 'audience' => 'pharmacy', 'operation' => 'orders.status.update', 'expected_revision' => 0, 'idempotency_key' => WorkbenchState::uuid(), 'canonical_code' => 'received'];
    workbenchDenied(fn () => $workbench->dispatch('order.action', [...$action, 'audience' => 'sender']), 'sender_order_actions_denied');
    workbenchDenied(fn () => $workbench->dispatch('order.action', [...$action, 'expected_revision' => 5]), 'order_action_revision_conflict');
    workbenchDenied(fn () => $workbench->dispatch('order.action', [...$action, 'canonical_code' => 'unknown']), 'order_action_vendor_mapping_required');
    workbenchDenied(fn () => $workbench->dispatch('order.action', [...$action, 'external_reference_handle' => 'forged']), 'order_action_parameters_invalid');
    $accepted = $workbench->dispatch('order.action', $action);
    workbenchCheck($accepted['action']['status'] === 'completed' && $accepted['order']['action_revision'] === 1, 'Canonical status action recorded once');
    $call = $runtime->calls[array_key_last($runtime->calls)];
    workbenchCheck($call['input']['status'] === 'in_progress' && $call['input']['allowed_status_codes'] === ['in_progress'], 'Host translates canonical status into one declared vendor value');
    workbenchCheck($call['input']['external_reference_handle'] === $order['pos_order_references'][0]['handle'], 'Host injects original owned reference');
    $count = count($runtime->calls);
    workbenchCheck($workbench->dispatch('order.action', $action)['action']['uuid'] === $accepted['action']['uuid'] && count($runtime->calls) === $count, 'Idempotent replay does not call the vendor twice');
    workbenchDenied(fn () => $workbench->dispatch('order.action', [...$action, 'canonical_code' => 'other']), 'order_action_idempotency_conflict');
    $shipping = ['order_id' => $order['uuid'], 'audience' => 'pharmacy', 'operation' => 'orders.shipping.update', 'expected_revision' => 1, 'idempotency_key' => WorkbenchState::uuid(), 'canonical_code' => 'ground', 'shipping' => []];
    workbenchDenied(fn () => $workbench->dispatch('order.action', [...$shipping, 'shipping' => ['service' => 'INJECTED']]), 'order_action_shipping_mapping_invalid');
    $shipped = $workbench->dispatch('order.action', $shipping);
    workbenchCheck($shipped['action']['status'] === 'completed' && $shipped['order']['action_revision'] === 2, 'Canonical string shipping action recorded');
    $call = $runtime->calls[array_key_last($runtime->calls)];
    workbenchCheck($call['input']['shipping']['service'] === 'GROUND-STD' && $call['input']['allowed_shipping_codes'] === ['GROUND-STD'], 'Declared string service type preserved like the real host');
    workbenchCheck($shipped['order']['status'] === 'submitted_to_pos', 'Vendor actions do not fabricate a canonical status change');
    workbenchCheck(! str_contains(json_encode($shipped['order']['actions']), 'external_reference_handle'), 'Action history remains a safe projection');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
