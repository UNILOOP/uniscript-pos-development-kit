<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\Workbench;

$root = sys_get_temp_dir().'/workbench-controlled-'.bin2hex(random_bytes(8));
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
    $submit = [...$scope, 'sender_id' => $ids['sender_id'], 'catalog_item_id' => $ids['catalog_item_id']];
    $cases = [
        ['controlled' => true],
        ['controlled' => true, 'verified_signed_payload' => []],
        ['controlled' => true, 'verified_signed_payload' => ['original' => 'fake-original', 'signature' => 'fake-signature']],
        ['controlled' => true, 'verified_signed_payload' => ['original_signed_payload' => base64_encode('synthetic-test-only'), 'detached_signature' => base64_encode('synthetic-not-a-signature'), 'verified' => true, 'verified_at' => gmdate('c'), 'provider' => 'EPCS-shaped synthetic metadata', 'sha256' => str_repeat('a', 64)]],
        ['controlled' => false, 'verified_signed_payload' => []],
        ['verified_signed_payload' => ['verified' => true]],
        ['controlled' => 1],
        ['order' => [...$fixture['order']['order'], 'controlled' => true]],
        ['order' => [...$fixture['order']['order'], 'medications' => [[...$fixture['order']['order']['medications'][0], 'is_controlled' => true]]]],
        ['order' => [...$fixture['order']['order'], 'medications' => [[...$fixture['order']['order']['medications'][0], 'schedule_code' => '2']]]],
    ];
    foreach ($cases as $index => $claims) {
        $before = count($runtime->calls);
        workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'blocked-controlled-'.$index, 'input' => [...$fixture['order'], ...$claims]]), 'controlled_orders_require_host_owned_verification');
        workbenchCheck(count($runtime->calls) === $before && $workbench->snapshot()['orders'] === [], 'The host rejects unsupported controlled claims before a generic package can ignore them or persist an order');
    }
    $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 1, 'values' => [...$fixture['mapping'], 'schedule_code' => '3']]);
    $before = count($runtime->calls);
    workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'controlled-host-mapping', 'input' => [...$fixture['order'], 'controlled' => false]]), 'controlled_orders_require_host_owned_verification');
    workbenchCheck(count($runtime->calls) === $before, 'A caller cannot hide a controlled host-selected mapping with a false flag');
    $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 2, 'values' => $fixture['mapping']]);
    $ordinary = $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'ordinary-non-controlled', 'input' => [...$fixture['order'], 'controlled' => false]]);
    workbenchCheck($ordinary['status'] === 'submitted_to_pos' && count($workbench->snapshot()['orders']) === 1, 'Supported non-controlled synthetic orders still complete normal package submission');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
