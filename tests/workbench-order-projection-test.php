<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\HostContract;
use UniScript\Integration\Result;
use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

$root = sys_get_temp_dir().'/workbench-order-projection-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$runtime = new class(new WorkbenchFixtureRuntime($root.'/builds'))
{
    public array $evidence = [];

    public function __construct(public readonly WorkbenchFixtureRuntime $fixture) {}

    public function prepare(string $source, string $execution): array
    {
        return $this->fixture->prepare($source, $execution);
    }

    public function invoke(array $deployment, string $operation, array $input, array $config, array $credentials, array $resources, array $scope): array
    {
        $scenario = $input['scenario'] ?? null;
        if ($operation !== 'orders.submit' || $scenario === 'accepted_then_lost' || ! is_string($scenario)) {
            $result = $this->fixture->invoke($deployment, $operation, $input, $config, $credentials, $resources, $scope);
            $this->evidence = $this->fixture->lastEvidence();

            return $result;
        }
        $kind = $scenario === 'remote_4xx' ? 'unknown_remote_outcome' : $scenario;
        $this->evidence = [];

        return (new Result($kind, $scenario === 'remote_4xx' ? 'fixture.managed_acceptance_unknown' : 'fixture.negative_result', [], [], $kind === 'unknown_remote_outcome' ? ['action' => 'operator_review', 'resubmit_allowed' => false] : []))->jsonSerialize();
    }

    public function lastEvidence(): array
    {
        return $this->evidence;
    }
};
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
    foreach (['validation_rejection', 'unsupported_operation', 'temporary_failure', 'permanent_failure', 'unknown_remote_outcome', 'success', 'remote_4xx'] as $scenario) {
        $order = $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'negative-'.$scenario, 'input' => [...$fixture['order'], 'scenario' => $scenario]]);
        $kind = $scenario === 'remote_4xx' ? 'unknown_remote_outcome' : $scenario;
        workbenchCheck($order['status'] === 'queued_for_pos' && $order['delivery_status'] === HostContract::read()['runtime']['result_statuses'][$kind] && $order['result']['kind'] === $kind, 'Canonical queued status, invocation status and result diagnostics remain separate: '.$scenario);
        $session = $workbench->openSession($connection['uuid'], 'pharmacy.order.details', 'negative-browser', 'http://127.0.0.1:19100', null, $order['uuid']);
        $projection = $workbench->bridge($session['session_id'], workbenchMessage($session, 'order.read'), 'negative-browser', 'http://127.0.0.1:19100');
        HostContract::assertProjection('order.read', $projection);
        workbenchCheck($projection['status'] === 'queued_for_pos' && $projection['delivery_status'] === $order['delivery_status'] && ! array_key_exists('result', $projection), 'Embedded order DTO matches the actual host shape: '.$scenario);
        $corrections = $workbench->bridge($session['session_id'], workbenchMessage($session, 'order.corrections.read', [], 'query', 'corrections'), 'negative-browser', 'http://127.0.0.1:19100');
        HostContract::assertProjection('order.corrections.read', $corrections);
        $canEdit = in_array($kind, ['validation_rejection', 'temporary_failure', 'permanent_failure', 'unknown_remote_outcome'], true);
        workbenchCheck($corrections['editable'] === $canEdit, 'Correction eligibility matches safe future-mapping edits: '.$scenario);
        if ($scenario === 'validation_rejection') {
            $beforeCount = count($workbench->snapshot()['orders']);
            workbenchDenied(fn () => $workbench->bridge($session['session_id'], workbenchMessage($session, 'mapping.save', ['catalog_item_uuid' => WorkbenchState::uuid(), 'values' => $fixture['mapping'], 'expected_revision' => 1], 'action', 'wrong-item'), 'negative-browser', 'http://127.0.0.1:19100'), 'order_mapping_scope_denied');
            $saved = $workbench->bridge($session['session_id'], workbenchMessage($session, 'mapping.save', ['catalog_item_uuid' => $ids['catalog_item_id'], 'values' => $fixture['mapping'], 'expected_revision' => 1], 'action', 'save'), 'negative-browser', 'http://127.0.0.1:19100');
            workbenchCheck($saved['revision'] === 2 && count($workbench->snapshot()['orders']) === $beforeCount, 'Mapping correction creates a revision without resubmitting an order');
            $original = array_values(array_filter($workbench->snapshot()['orders'], static fn (array $candidate): bool => $candidate['uuid'] === $order['uuid']))[0];
            workbenchCheck($original['mapping_revision'] === 1, 'Submitted order retains its original mapping revision');
        }
        workbenchDenied(fn () => HostContract::assertProjection('order.read', [...$projection, 'status' => 'unknown']), 'schema_enum:$.status');
        workbenchDenied(fn () => HostContract::assertProjection('order.read', [...$projection, 'delivery_status' => 'validation_rejection']), 'schema_enum:$.delivery_status');
    }
    $unknown = $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'unknown-with-owned-reference', 'input' => [...$fixture['order'], 'scenario' => 'accepted_then_lost']]);
    $read = $workbench->dispatch('order.action', ['order_id' => $unknown['uuid'], 'operation' => 'orders.reconcile', 'expected_revision' => 0, 'idempotency_key' => WorkbenchState::uuid()]);
    workbenchCheck($read['action']['status'] === 'completed' && $read['order']['status'] === 'queued_for_pos' && $read['order']['delivery_status'] === 'unknown' && $read['order']['result']['kind'] === 'unknown_remote_outcome', 'A reconciliation receipt cannot invent a canonical lifecycle transition or rewrite original dispatch outcome');
    $workbench->dispatch('webhook.create', [...$scope, 'username' => 'negative-fixture', 'password' => 'independent-synthetic-password-at-least-24']);
    workbenchDenied(fn () => $workbench->simulateCallback($unknown['uuid'], 'Delivered'), 'callback_reference_not_owned');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
