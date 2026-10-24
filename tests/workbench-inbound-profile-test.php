<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\InboundProfile;
use UniScript\Integration\Result;
use UniScript\Integration\Workbench;

$root = sys_get_temp_dir().'/workbench-inbound-profile-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
$fixture['inbound_profile'] = ['version' => 1, 'authentication' => 'basic', 'label' => 'Other vendor callback wire format', 'reference_field' => 'payload.order.providerId', 'event_id_field' => 'meta.eventId', 'account_fields' => ['tenant.scope' => 'account_id'], 'package_ids' => [$fixture['package_id']]];
$runtime = new class(new WorkbenchFixtureRuntime($root.'/builds'))
{
    public array $evidence = [];

    public int $decodes = 0;

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
        $event = $input['events'][0];
        $this->evidence = ['proposals' => [['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event' => ['external_order_reference' => InboundProfile::value($event, 'payload.order.providerId'), 'event_id' => (string) InboundProfile::value($event, 'meta.eventId'), 'normalized_status' => $event['status'], 'tracking_number' => $event['trackingNumber'] ?? null, 'carrier' => 'Synthetic carrier']]]];

        return (new Result('success', 'fixture.custom_callback'))->jsonSerialize();
    }

    public function lastEvidence(): array
    {
        return $this->evidence;
    }
};
$workbench = new Workbench($root.'/state', $runtime, [$fixture]);
try {
    workbenchDenied(fn () => InboundProfile::validate([...$fixture['inbound_profile'], 'reference_field' => '../order'], $fixture['package_id']), 'inbound_profile_invalid');
    workbenchDenied(fn () => InboundProfile::validate($fixture['inbound_profile'], 'other/package'), 'inbound_profile_invalid');
    $package = $workbench->load(dirname(__DIR__).'/examples/reference');
    $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $scope = ['connection_id' => $connection['uuid']];
    $ids = $workbench->snapshot()['fixtures'];
    $workbench->dispatch('connection.health', $scope);
    $workbench->dispatch('connection.test', $scope);
    $workbench->dispatch('connection.activate', $scope);
    $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 0, 'values' => $fixture['mapping']]);
    $submit = [...$scope, 'sender_id' => $ids['sender_id'], 'catalog_item_id' => $ids['catalog_item_id'], 'idempotency_key' => 'custom-inbound-old', 'input' => $fixture['order']];
    $old = $workbench->dispatch('order.submit', $submit);
    $endpoint = $workbench->dispatch('webhook.create', [...$scope, 'username' => 'custom-wire', 'password' => 'independent-synthetic-password-at-least-24']);
    $wire = static fn (array $order, string $event, string $status, string $account): array => ['payload' => ['order' => ['providerId' => 'synthetic-vendor-'.$order['uuid']]], 'meta' => ['eventId' => $event], 'tenant' => ['scope' => $account], 'status' => $status, 'trackingNumber' => 'SYNTHETIC-'.$order['uuid']];
    $receive = static fn (array $body): array => $workbench->receive($endpoint['uuid'], 'custom-wire', 'independent-synthetic-password-at-least-24', json_encode($body, JSON_THROW_ON_ERROR));
    workbenchDenied(fn () => $receive($wire($old, 'wrong-account', 'shipped', 'another-account')), 'callback_account_mismatch');
    workbenchCheck($runtime->decodes === 0, 'Account ownership is checked before any package decoder');
    $forged = $wire($old, 'wrong-reference', 'shipped', $fixture['configuration']['account_id']);
    $forged['payload']['order']['providerId'] = 'unowned-reference';
    $forged['orderId'] = 'synthetic-vendor-'.$old['uuid'];
    workbenchDenied(fn () => $receive($forged), 'callback_reference_not_owned');
    $oldShipped = $wire($old, 'custom-old-shipped', 'shipped', $fixture['configuration']['account_id']);
    workbenchCheck($receive($oldShipped)['status'] === 'processed', 'Custom nested reference/event/account fields process without vendor branches');
    workbenchCheck($receive($oldShipped)['duplicate'], 'Custom wire exact replay keeps its receipt');
    $changed = $oldShipped;
    $changed['trackingNumber'] = 'DIFFERENT';
    workbenchCheck($receive($changed)['status'] === 'needs_review', 'Custom source event ID cannot be reused with different content');
    $nextRoot = $root.'/next-package';
    workbenchCopy(dirname(__DIR__).'/examples/reference', $nextRoot);
    $manifest = json_decode(file_get_contents($nextRoot.'/integration.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['version'] = '1.0.1';
    file_put_contents($nextRoot.'/integration.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $nextPackage = $workbench->load($nextRoot);
    $next = $workbench->dispatch('connection.upgrade', [...$scope, 'execution_revision' => $nextPackage['execution_revision'], 'copy_credentials' => true]);
    $nextScope = ['connection_id' => $next['uuid']];
    $saved = $workbench->dispatch('connection.save', [...$nextScope, 'expected_version' => $next['version'], 'config' => [...$fixture['configuration'], 'account_id' => 'upgraded-account']]);
    $workbench->dispatch('connection.health', $nextScope);
    $workbench->dispatch('connection.test', $nextScope);
    $workbench->dispatch('connection.activate', $nextScope);
    $new = $workbench->dispatch('order.submit', [...$submit, ...$nextScope, 'idempotency_key' => 'custom-inbound-new']);
    workbenchCheck($next['webhooks'][0]['uuid'] === $endpoint['uuid'], 'Custom inbound profile endpoint remains stable across upgrade');
    workbenchDenied(fn () => $receive($wire($old, 'wrong-old-config', 'delivered', 'upgraded-account')), 'callback_account_mismatch');
    workbenchCheck($receive($wire($old, 'custom-old-delivered', 'delivered', $fixture['configuration']['account_id']))['status'] === 'processed', 'Old order uses its original pinned account after an upgrade');
    workbenchCheck($receive($wire($new, 'custom-new-delivered', 'delivered', 'upgraded-account'))['status'] === 'processed', 'New order uses its upgraded pinned account through the same endpoint');
    workbenchCheck(array_all($workbench->snapshot()['orders'], static fn (array $order): bool => $order['status'] === 'completed' && count($order['fulfillments']) === 1), 'Old and new executions both complete their own parcel');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
