<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\Workbench;

$root = sys_get_temp_dir().'/workbench-callback-'.bin2hex(random_bytes(8));
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
    $order = $workbench->dispatch('order.submit', [...$scope, 'sender_id' => $ids['sender_id'], 'catalog_item_id' => $ids['catalog_item_id'], 'idempotency_key' => 'callback-parity', 'input' => $fixture['order']]);
    $endpoint = $workbench->dispatch('webhook.create', [...$scope, 'username' => 'local-inbound', 'password' => 'independent-synthetic-password-at-least-24']);
    $current = static fn (): array => array_find($workbench->snapshot()['orders'], static fn (array $value): bool => $value['uuid'] === $order['uuid']);
    $receive = static function (string $id, string $status, string $tracking = '') use ($workbench, $endpoint, $order): array {
        return $workbench->receive($endpoint['uuid'], 'local-inbound', 'independent-synthetic-password-at-least-24', json_encode(['eventId' => $id, 'orderId' => 'synthetic-vendor-'.$order['uuid'], 'status' => $status, 'trackingNumber' => $tracking, 'carrier' => 'Synthetic carrier'], JSON_THROW_ON_ERROR));
    };
    foreach (['accepted', 'ready', 'in_transit', 'completed'] as $unsupported) {
        workbenchCheck($receive('held-'.$unsupported, $unsupported)['status'] === 'needs_review' && $current()['status'] === 'submitted_to_pos', 'Unsupported normalized status requires review: '.$unsupported);
    }
    workbenchCheck($receive('queued', 'queued')['status'] === 'processed' && $current()['status'] === 'accepted', 'Queued maps to accepted like the canonical host');
    workbenchCheck($receive('ready', 'ready_for_pickup')['status'] === 'processed' && $current()['status'] === 'ready' && $current()['fulfillments'] === [], 'Ready for pickup does not invent a parcel');
    workbenchCheck($receive('untracked-shipped', 'shipped')['status'] === 'processed' && $current()['fulfillments'] === [], 'Untracked shipped event does not invent a shipment');
    $first = $receive('parcel-a-shipped', 'shipped', 'SYNTHETIC-A');
    $parcelA = $current()['fulfillments'][0]['uuid'];
    workbenchCheck($first['status'] === 'processed' && count($current()['fulfillments']) === 1, 'Tracked shipped event creates one scoped parcel');
    $receive('parcel-b-shipped', 'shipped', 'SYNTHETIC-B');
    workbenchCheck(count($current()['fulfillments']) === 2, 'A second tracking number creates a separate parcel');
    $receive('parcel-a-delivered', 'delivered', 'synthetic-a');
    workbenchCheck($current()['status'] === 'completed' && $current()['fulfillments'][0]['uuid'] === $parcelA && $current()['fulfillments'][0]['delivered'] && ! $current()['fulfillments'][1]['delivered'], 'Case-insensitive tracking match updates only its parcel');
    workbenchCheck($receive('parcel-b-shipped', 'shipped', 'DIFFERENT-TRACKING')['status'] === 'needs_review' && count($current()['fulfillments']) === 2, 'Changed body cannot reuse an immutable source event identity');
    $receive('parcel-b-delivered', 'delivered', 'SYNTHETIC-B');
    workbenchCheck(array_all($current()['fulfillments'], static fn (array $value): bool => $value['delivered']), 'Delivery completes the second existing parcel');
    $receive('parcel-a-late-shipped', 'shipped', 'SYNTHETIC-A');
    workbenchCheck($current()['status'] === 'completed' && array_all($current()['fulfillments'], static fn (array $value): bool => $value['delivered']), 'Late shipped event cannot regress delivered parcels or order');
    workbenchCheck(! str_contains(json_encode($current()), 'SYNTHETIC-A') && ! str_contains(json_encode($current()), 'fulfillment_tracking'), 'Safe projections omit tracking and private deduplication keys');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
