<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\Result;
use UniScript\Integration\Workbench;

$root = sys_get_temp_dir().'/workbench-callback-batch-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
$runtime = new class(new WorkbenchFixtureRuntime($root.'/builds'))
{
    public array $evidence = [];

    public int $decodes = 0;

    public bool $failed = false;

    public function __construct(public readonly WorkbenchFixtureRuntime $fixture) {}

    public function prepare(string $source, string $execution): array
    {
        return $this->fixture->prepare($source, $execution);
    }

    public function invoke(array $deployment, string $operation, array $input, array $config, array $credentials, array $resources, array $scope): array
    {
        if ($operation !== 'inbound.decode') {
            $result = $this->fixture->invoke($deployment, $operation, $input, $config, $credentials, $resources, $scope);
            $this->evidence = $this->fixture->lastEvidence();

            return $result;
        }
        $this->decodes++;
        $this->evidence = ['proposals' => []];
        foreach ($input['events'] as $event) {
            $this->evidence['proposals'][] = ['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event' => ['external_order_reference' => $event['orderId'], 'event_id' => ($event['omit_proposal_id'] ?? false) ? null : ($event['eventId'] ?? null), 'normalized_status' => $event['status'], 'tracking_number' => $event['trackingNumber'] ?? null, 'carrier' => 'Synthetic carrier']];
        }

        return (new Result($this->failed ? 'permanent_failure' : 'success', 'fixture.batch_callback'))->jsonSerialize();
    }

    public function lastEvidence(): array
    {
        return $this->evidence;
    }
};
$workbench = new Workbench($root.'/state', $runtime);
try {
    $package = $workbench->load(dirname(__DIR__).'/examples/reference');
    $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $scope = ['connection_id' => $connection['uuid']];
    $ids = $workbench->snapshot()['fixtures'];
    $workbench->dispatch('connection.health', $scope);
    $workbench->dispatch('connection.test', $scope);
    $workbench->dispatch('connection.activate', $scope);
    $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 0, 'values' => $fixture['mapping']]);
    $submit = [...$scope, 'sender_id' => $ids['sender_id'], 'catalog_item_id' => $ids['catalog_item_id'], 'input' => $fixture['order']];
    $orders = [];
    foreach (range(1, 4) as $index) {
        $orders[] = $workbench->dispatch('order.submit', [...$submit, 'idempotency_key' => 'batch-order-'.$index]);
    }
    $endpoint = $workbench->dispatch('webhook.create', [...$scope, 'username' => 'batch-wire', 'password' => 'independent-synthetic-password-at-least-24']);
    $event = static fn (array $order, string $status, ?string $id = null): array => ['orderId' => 'synthetic-vendor-'.$order['uuid'], 'eventId' => $id, 'status' => $status, 'trackingNumber' => 'SYNTHETIC-'.$order['uuid']];
    $receive = static fn (array $body): array => $workbench->receive($endpoint['uuid'], 'batch-wire', 'independent-synthetic-password-at-least-24', json_encode($body, JSON_THROW_ON_ERROR));
    $current = static fn (array $order): array => array_find($workbench->snapshot()['orders'], static fn (array $value): bool => $value['uuid'] === $order['uuid']);
    workbenchDenied(fn () => $receive(['events' => []]), 'webhook_events_invalid');
    workbenchDenied(fn () => $receive(array_fill(0, 101, $event($orders[0], 'shipped', 'too-many'))), 'webhook_events_invalid');
    $batch = ['events' => [$event($orders[0], 'queued', 'batch-queued'), $event($orders[1], 'in_progress', 'batch-progress')]];
    workbenchCheck($receive($batch)['status'] === 'processed' && $current($orders[0])['status'] === 'accepted' && $current($orders[1])['status'] === 'in_progress', 'Bounded object event batches update their own owned orders');
    $list = [$event($orders[0], 'delivered', 'batch-delivered-a'), $event($orders[1], 'delivered', 'batch-delivered-b')];
    workbenchCheck($receive($list)['status'] === 'processed' && $current($orders[0])['status'] === 'completed' && $current($orders[1])['status'] === 'completed', 'Raw list callbacks apply every proposal through the same pinned decoder');
    $withoutId = $event($orders[2], 'shipped');
    unset($withoutId['eventId']);
    workbenchCheck($receive($withoutId)['status'] === 'processed' && $receive($withoutId)['duplicate'], 'Absent event IDs use immutable source body identity');
    $nullProposal = [...$event($orders[2], 'delivered', 'source-identity-kept'), 'omit_proposal_id' => true];
    workbenchCheck($receive($nullProposal)['status'] === 'processed', 'Nullable proposal event ID is supported by the canonical host');
    workbenchCheck($receive([...$nullProposal, 'trackingNumber' => 'CHANGED'])['status'] === 'needs_review', 'Omitting proposal ID cannot evade source event identity immutability');
    $runtime->failed = true;
    workbenchCheck($receive($event($orders[3], 'delivered', 'failed-decoder'))['status'] === 'needs_review' && $current($orders[3])['status'] === 'submitted_to_pos', 'Failed decoder results cannot apply emitted proposals');
    $runtime->failed = false;
    workbenchCheck($receive([$event($orders[3], 'shipped', 'ambiguous'), $event($orders[3], 'shipped', 'ambiguous')])['status'] === 'needs_review' && $current($orders[3])['status'] === 'submitted_to_pos', 'Ambiguous repeated batch sources require review');
    $nextRoot = $root.'/next';
    workbenchCopy(dirname(__DIR__).'/examples/reference', $nextRoot);
    $manifest = json_decode(file_get_contents($nextRoot.'/integration.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['version'] = '1.0.1';
    file_put_contents($nextRoot.'/integration.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $nextPackage = $workbench->load($nextRoot);
    $next = $workbench->dispatch('connection.upgrade', [...$scope, 'execution_revision' => $nextPackage['execution_revision'], 'copy_credentials' => true]);
    $nextScope = ['connection_id' => $next['uuid']];
    $workbench->dispatch('connection.health', $nextScope);
    $workbench->dispatch('connection.test', $nextScope);
    $workbench->dispatch('connection.activate', $nextScope);
    $new = $workbench->dispatch('order.submit', [...$submit, ...$nextScope, 'idempotency_key' => 'new-decoder']);
    $before = $runtime->decodes;
    workbenchDenied(fn () => $receive([$event($orders[3], 'shipped', 'old-mixed'), $event($new, 'shipped', 'new-mixed')]), 'callback_decoder_binding_ambiguous');
    workbenchCheck($runtime->decodes === $before, 'Mixed original executions are denied before any decoder is invoked');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
