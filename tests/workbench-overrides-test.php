<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

$root = sys_get_temp_dir().'/workbench-overrides-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$runtime = new WorkbenchFixtureRuntime($root.'/builds');
$workbench = new Workbench($root.'/state', $runtime);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
$reconcile = static function (string $orderId) use ($workbench): array {
    $order = array_find($workbench->snapshot()['orders'], static fn (array $value): bool => $value['uuid'] === $orderId);

    return $workbench->dispatch('order.action', ['order_id' => $orderId, 'operation' => 'orders.reconcile', 'expected_revision' => $order['action_revision'] ?? 0, 'idempotency_key' => WorkbenchState::uuid()]);
};
try {
    $package = $workbench->load(dirname(__DIR__).'/examples/reference');
    $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $scope = ['connection_id' => $connection['uuid']];
    $ids = $workbench->snapshot()['fixtures'];
    $sender = [...$scope, 'sender_id' => $ids['sender_id']];
    $submit = [...$sender, 'catalog_item_id' => $ids['catalog_item_id'], 'input' => $fixture['order']];
    $workbench->dispatch('connection.health', $scope);
    $workbench->dispatch('connection.test', $scope);
    $workbench->dispatch('connection.activate', $scope);
    $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 0, 'values' => $fixture['mapping']]);
    $workbench->dispatch('override.save', [...$sender, 'credentials' => ['api_key' => 'sender-first-private']]);
    workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'draft']), 'sender_override_not_enabled');
    $workbench->dispatch('override.test', $sender);
    $first = $workbench->dispatch('override.enable', $sender)['sender_overrides'][$ids['sender_id']]['revision'];
    $old = $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'first']);
    workbenchCheck($old['sender_override'], 'Tested sender credentials selected');
    $workbench->dispatch('override.disable', $sender);
    workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'disabled']), 'sender_override_not_enabled');
    $workbench->dispatch('override.remove', $sender);
    $base = $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'removed']);
    workbenchCheck(! $base['sender_override'], 'Only explicit removal restores default credentials');
    $workbench->dispatch('override.save', [...$sender, 'credentials' => ['api_key' => 'sender-second-private']]);
    $reconcile($old['uuid']);
    workbenchCheck($runtime->calls[array_key_last($runtime->calls)]['credentials']['api_key'] === 'sender-first-private', 'Rotation retains original outbound credential pin');
    $workbench->dispatch('override.revoke', [...$sender, 'revision' => $first]);
    workbenchDenied(fn () => $reconcile($old['uuid']), 'sender_credentials_pin_revoked');
    $workbench->dispatch('override.test', $sender);
    $workbench->dispatch('override.enable', $sender);
    $new = $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'second']);
    workbenchCheck($new['sender_override'], 'Historical revocation does not revoke the replacement');
    $current = array_find($workbench->snapshot()['connections'], static fn (array $value): bool => $value['uuid'] === $scope['connection_id']);
    $saved = $workbench->dispatch('connection.save', [...$scope, 'expected_version' => $current['version'], 'config' => [...$fixture['configuration'], 'display_label' => 'Changed scope']]);
    workbenchCheck(! $saved['sender_overrides'][$ids['sender_id']]['tested'], 'Settings change invalidates sender evidence');
    $workbench->dispatch('connection.health', $scope);
    $workbench->dispatch('connection.test', $scope);
    workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'stale-override']), 'sender_override_test_required');
    $reconcile($new['uuid']);
    workbenchCheck($runtime->calls[array_key_last($runtime->calls)]['configuration'] === $fixture['configuration'], 'Existing order retains original settings after edits');
    $workbench->dispatch('override.test', $sender);
    $workbench->dispatch('override.enable', $sender);
    $workbench->dispatch('override.revoke', $sender);
    workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'revoked']), 'sender_override_not_enabled');
    workbenchDenied(fn () => $reconcile($new['uuid']), 'sender_credentials_pin_revoked');
    $workbench->dispatch('override.remove', $sender);
    workbenchCheck(! $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'removed-revoked'])['sender_override'], 'Explicit removal works after revocation without restoring old pins');
    $workbench->dispatch('override.save', [...$sender, 'credentials' => ['api_key' => 'rejected']]);
    $failed = $workbench->dispatch('override.test', $sender);
    workbenchCheck(! $failed['sender_overrides'][$ids['sender_id']]['tested'] && ! $failed['sender_overrides'][$ids['sender_id']]['enabled'], 'Failed test clears readiness');
    workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'failed-test']), 'sender_override_not_enabled');
    workbenchCheck(! str_contains(json_encode($workbench->snapshot()), 'sender-first-private'), 'History projection does not reveal credentials');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
