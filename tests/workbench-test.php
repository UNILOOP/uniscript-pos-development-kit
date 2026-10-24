<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\HostContract;
use UniScript\Integration\Manifest;
use UniScript\Integration\Result;
use UniScript\Integration\StaticUi;
use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

final class WorkbenchFixtureRuntime
{
    public array $calls = [];

    public array $evidence = [];

    public function __construct(private readonly string $directory) {}

    public function prepare(string $source, string $execution): array
    {
        $root = $this->directory.'/'.$execution;
        workbenchCopy($source, $root);
        $manifest = Manifest::read($root);

        return ['manifest' => $manifest, 'execution_revision' => $execution, 'package_root' => $root, 'source_sha256' => hash_file('sha256', $root.'/integration.json'), 'status' => 'development_ready'];
    }

    public function invoke(array $deployment, string $operation, array $input, array $configuration, array $credentials, array $resources, array $scope): array
    {
        $this->calls[] = compact('operation', 'input', 'configuration', 'credentials', 'resources', 'scope');
        $this->evidence = [];
        if (in_array('rejected', $credentials, true)) {
            return (new Result('permanent_failure', 'fixture.credentials_rejected'))->jsonSerialize();
        }
        if ($operation === 'orders.submit' && ($input['scenario'] ?? null) === 'accepted_then_lost') {
            $this->evidence = ['external_references' => [['handle' => 'handle-'.$scope['order_id'], 'reference' => 'synthetic-vendor-'.$scope['order_id']]]];

            return (new Result('unknown_remote_outcome', 'fixture.response_lost', [], [], ['action' => 'reconcile', 'resubmit_allowed' => false]))->jsonSerialize();
        }
        if (in_array($operation, ['orders.submit', 'orders.reconcile', 'orders.status.update', 'orders.shipping.update'], true)) {
            $reference = 'synthetic-vendor-'.$scope['order_id'];
            $this->evidence = ['external_references' => [['handle' => 'handle-'.$scope['order_id'], 'reference' => $reference]]];

            return (new Result('remote_acceptance', 'fixture.accepted', ['external_reference_handle' => 'handle-'.$scope['order_id']]))->jsonSerialize();
        }
        if ($operation === 'inbound.decode') {
            $event = $input['events'][0];
            $this->evidence = ['proposals' => [['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event' => ['external_order_reference' => $event['orderId'], 'event_id' => $event['eventId'], 'normalized_status' => $event['status'], 'tracking_number' => $event['trackingNumber'], 'carrier' => $event['carrier']]]]];
        }

        return (new Result('success', 'fixture.succeeded'))->jsonSerialize();
    }

    public function lastEvidence(): array
    {
        return $this->evidence;
    }
}

function workbenchCopy(string $source, string $target): void
{
    mkdir($target, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Test source must not contain symlinks.');
        }
        $destination = $target.'/'.substr($file->getPathname(), strlen($source) + 1);
        if ($file->isDir()) {
            mkdir($destination, 0700, true);
        } else {
            copy($file->getPathname(), $destination);
        }
    }
}

function workbenchRemove(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

$checks = 0;
function workbenchCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function workbenchDenied(callable $action, string $reason): void
{
    try {
        $action();
    } catch (InvalidArgumentException|RuntimeException $exception) {
        workbenchCheck($exception->getMessage() === $reason, 'Expected '.$reason.', got '.$exception->getMessage());

        return;
    }
    throw new RuntimeException('Expected denial: '.$reason);
}

function workbenchMessage(array $session, string $operation, array $arguments = [], string $kind = 'query', string $id = 'request-1'): array
{
    return ['version' => '1', 'id' => $id, 'nonce' => $session['nonce'], 'session_id' => $session['session_id'], 'execution_revision' => $session['execution_revision'], 'expected_revision' => $session['expected_revision'], 'kind' => $kind, 'operation' => $operation, 'arguments' => $arguments];
}

if (defined('WORKBENCH_FIXTURES_ONLY') && WORKBENCH_FIXTURES_ONLY) {
    return;
}

$root = sys_get_temp_dir().'/uniscript-workbench-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$runtime = new WorkbenchFixtureRuntime($root.'/builds');
$workbench = new Workbench($root.'/state', $runtime);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, 32, JSON_THROW_ON_ERROR);
$host = 'http://127.0.0.1:19100';
$browser = 'test-browser-a';
try {
    $package = $workbench->load(dirname(__DIR__).'/examples/reference');
    workbenchCheck($package['manifest']['package_id'] === $fixture['package_id'], 'Reference package identity');
    $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => ['api_key' => 'private-test-api-value']]);
    $id = $connection['uuid'];
    $scope = ['connection_id' => $id];
    $item = $workbench->snapshot()['fixtures']['catalog_item_id'];
    $sender = $workbench->snapshot()['fixtures']['sender_id'];
    $submit = [...$scope, 'sender_id' => $sender, 'catalog_item_id' => $item, 'idempotency_key' => 'test-order-1', 'input' => $fixture['order']];
    workbenchDenied(fn () => $workbench->dispatch('connection.activate', $scope), 'fresh_health_and_test_required');
    workbenchDenied(fn () => $workbench->dispatch('order.submit', $submit), 'active_connection_and_fresh_evidence_required');
    workbenchDenied(fn () => $workbench->dispatch('connection.health', [...$scope, 'environment' => 'production']), 'connection_scope_mismatch');
    $health = $workbench->dispatch('connection.health', $scope);
    workbenchCheck($health['connection']['health_current'], 'Health evidence current');
    $workbench->dispatch('connection.test', $scope);
    $connection = $workbench->dispatch('connection.activate', $scope);
    workbenchCheck($connection['status'] === 'active', 'Connection activated');
    $mapping = $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $item, 'expected_revision' => 0, 'values' => $fixture['mapping']]);
    workbenchCheck($mapping['revision'] === 1, 'First mapping revision');
    workbenchDenied(fn () => $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $item, 'expected_revision' => 0, 'values' => $fixture['mapping']]), 'mapping_revision_conflict');
    $resource = $workbench->dispatch('resource.fixture.save', [...$scope, 'resource_type' => 'status_mapping', 'resource_key' => 'shipped', 'expected_revision' => 0, 'values' => ['vendor_code' => 'ready']]);
    workbenchCheck($resource['revision'] === 1 && $resource['local_fixture_only'], 'Typed local resource fixture validated');
    workbenchDenied(fn () => $workbench->dispatch('resource.fixture.save', [...$scope, 'resource_type' => 'status_mapping', 'resource_key' => 'shipped', 'expected_revision' => 0, 'values' => ['vendor_code' => 'ready']]), 'resource_revision_conflict');
    workbenchDenied(fn () => $workbench->dispatch('mapping.save', [...$scope, 'resource_type' => 'status_mapping', 'catalog_item_id' => $item, 'expected_revision' => 1, 'values' => []]), 'use_resource_fixture_editor');
    $workbench->dispatch('override.save', [...$scope, 'sender_id' => $sender, 'credentials' => ['api_key' => 'private-test-sender-value']]);
    workbenchDenied(fn () => $workbench->dispatch('override.enable', [...$scope, 'sender_id' => $sender]), 'sender_override_test_required');
    $workbench->dispatch('override.test', [...$scope, 'sender_id' => $sender]);
    $workbench->dispatch('override.enable', [...$scope, 'sender_id' => $sender]);
    $order = $workbench->dispatch('order.submit', $submit);
    workbenchCheck($order['status'] === 'submitted_to_pos' && $order['sender_override'], 'Accepted order uses tested sender override');
    workbenchCheck($workbench->dispatch('order.submit', $submit)['uuid'] === $order['uuid'], 'Duplicate submit returns same immutable order');
    workbenchDenied(fn () => $workbench->dispatch('order.submit', [...$submit, 'input' => [...$fixture['order'], 'scenario' => 'different']]), 'order_idempotency_conflict');
    workbenchCheck(count(array_filter($runtime->calls, static fn (array $call): bool => $call['operation'] === 'orders.submit')) === 1, 'Package submit executed once');
    $submitCall = array_find($runtime->calls, static fn (array $call): bool => $call['operation'] === 'orders.submit');
    workbenchCheck($submitCall['credentials']['api_key'] === 'private-test-sender-value' && $submitCall['input']['order']['medications'][0]['mapping'] === $fixture['mapping'], 'Host owns credential and product mapping projection');
    $safeSnapshot = json_encode($workbench->snapshot(), JSON_THROW_ON_ERROR);
    workbenchCheck(! str_contains($safeSnapshot, 'private-test-') && ! str_contains(file_get_contents($root.'/state/state.json'), 'private-test-'), 'Secrets encrypted and absent from public state');
    $setup = $workbench->openSession($id, 'connection.setup', $browser, $host);
    foreach (['context.read', 'config.read', 'reference.timezones'] as $operation) {
        $projection = $workbench->bridge($setup['session_id'], workbenchMessage($setup, $operation, [], 'query', 'query-'.str_replace('.', '-', $operation)), $browser, $host);
        HostContract::assertProjection($operation, $projection);
        workbenchCheck(! str_contains(json_encode($projection), 'private-test-'), 'Setup projection redacted: '.$operation);
    }
    workbenchDenied(fn () => $workbench->bridge($setup['session_id'], workbenchMessage($setup, 'config.read'), 'other-browser', $host), 'bridge_session_denied');
    workbenchDenied(fn () => $workbench->bridge($setup['session_id'], workbenchMessage($setup, 'config.read'), $browser, 'http://127.0.0.1:19101'), 'bridge_session_denied');
    $invalidNonce = workbenchMessage($setup, 'config.read');
    $invalidNonce['nonce'] = str_repeat('a', 64);
    workbenchDenied(fn () => $workbench->bridge($setup['session_id'], $invalidNonce, $browser, $host), 'bridge_session_denied');
    $workbench->bridge($setup['session_id'], workbenchMessage($setup, 'context.read'), $browser, $host);
    workbenchDenied(fn () => $workbench->bridge($setup['session_id'], workbenchMessage($setup, 'config.read'), $browser, $host), 'bridge_request_replay_conflict');
    workbenchDenied(fn () => $workbench->bridge($setup['session_id'], workbenchMessage($setup, 'context.read', ['__proto__' => ['admin' => true]], 'query', 'unsafe-1'), $browser, $host), 'bridge_unsafe_key');
    $mappingSession = $workbench->openSession($id, 'catalog.item.mapping', $browser, $host, $item);
    $mappingRead = $workbench->bridge($mappingSession['session_id'], workbenchMessage($mappingSession, 'mapping.read'), $browser, $host);
    HostContract::assertProjection('mapping.read', $mappingRead);
    workbenchCheck($mappingRead['values'] === $fixture['mapping'], 'Mapping projection real published values');
    foreach (['pharmacy', 'sender'] as $audience) {
        $session = $workbench->openSession($id, $audience.'.order.details', $browser, $host, null, $order['uuid']);
        workbenchCheck($session['actions'] === [], 'Order audience is read only');
        $read = $workbench->bridge($session['session_id'], workbenchMessage($session, 'order.read'), $browser, $host);
        HostContract::assertProjection('order.read', $read);
        workbenchCheck(! str_contains(json_encode($read), 'Synthetic Patient') && ! str_contains(json_encode($read), 'synthetic-vendor-'), 'Order projection has no raw clinical/reference data');
        workbenchDenied(fn () => $workbench->bridge($session['session_id'], workbenchMessage($session, 'config.read'), $browser, $host), 'bridge_operation_denied');
        workbenchDenied(fn () => $workbench->bridge($session['session_id'], workbenchMessage($session, 'config.save', ['config' => []], 'action'), $browser, $host), 'bridge_operation_denied');
    }
    workbenchDenied(fn () => $workbench->dispatch('order.action', ['order_id' => $order['uuid'], 'audience' => 'sender', 'operation' => 'orders.reconcile']), 'sender_order_actions_denied');
    workbenchDenied(fn () => $workbench->dispatch('webhook.create', [...$scope, 'username' => 'test-user', 'password' => 'short']), 'webhook_password_minimum_24_characters');
    $webhook = $workbench->dispatch('webhook.create', [...$scope, 'username' => 'test-user', 'password' => 'private-incoming-password-at-least-24']);
    $before = count($workbench->snapshot()['events']);
    workbenchDenied(fn () => $workbench->receive($webhook['uuid'], 'wrong', 'wrong', '{}'), 'webhook_unauthorized');
    workbenchCheck(count($workbench->snapshot()['events']) === $before, 'Authentication denied before receipt persistence');
    $unrelated = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials'], 'organization_id' => 'different-synthetic-organization']);
    $otherEndpoint = $workbench->dispatch('webhook.create', ['connection_id' => $unrelated['uuid'], 'username' => 'other-user', 'password' => 'another-private-incoming-password']);
    workbenchDenied(fn () => $workbench->receive($otherEndpoint['uuid'], 'other-user', 'another-private-incoming-password', json_encode(['orderId' => 'synthetic-vendor-'.$order['uuid'], 'eventId' => 'wrong-lineage', 'status' => 'shipped'], JSON_THROW_ON_ERROR)), 'callback_reference_not_owned');
    foreach (['received' => 'accepted', 'in_progress' => 'in_progress', 'ready_for_pickup' => 'ready'] as $vendorStatus => $expectedStatus) {
        $event = ['orderId' => 'synthetic-vendor-'.$order['uuid'], 'eventId' => 'workflow-'.$vendorStatus, 'status' => $vendorStatus, 'trackingNumber' => '', 'carrier' => 'Synthetic carrier'];
        $progress = $workbench->receive($webhook['uuid'], 'test-user', 'private-incoming-password-at-least-24', json_encode($event, JSON_THROW_ON_ERROR));
        $read = array_find($workbench->snapshot()['orders'], static fn (array $value): bool => $value['uuid'] === $order['uuid']);
        workbenchCheck($progress['status'] === 'processed' && $read['status'] === $expectedStatus && $read['fulfillments'] === [], 'Order progress does not fabricate a shipment: '.$vendorStatus);
    }
    $receipt = $workbench->simulateCallback($order['uuid'], 'Shipped');
    workbenchCheck($receipt['status'] === 'processed', 'Authenticated shipped callback decoded and applied');
    $duplicate = $workbench->simulateCallback($order['uuid'], 'Shipped');
    workbenchCheck($duplicate['receipt_handle'] === $receipt['receipt_handle'] && $duplicate['duplicate'], 'Duplicate callback shares receipt');
    $delivered = $workbench->simulateCallback($order['uuid'], 'Delivered');
    workbenchCheck($delivered['status'] === 'processed', 'Authenticated delivered callback applied');
    $latest = array_find($workbench->snapshot()['orders'], static fn (array $value): bool => $value['uuid'] === $order['uuid']);
    workbenchCheck($latest['status'] === 'completed' && count($latest['fulfillments']) === 1 && $latest['fulfillments'][0]['delivered'], 'One parcel progresses to delivery');
    $workbench->simulateCallback($order['uuid'], 'Shipped');
    $latest = array_find($workbench->snapshot()['orders'], static fn (array $value): bool => $value['uuid'] === $order['uuid']);
    workbenchCheck($latest['status'] === 'completed', 'Old shipped replay cannot regress completed order');
    $newSource = $root.'/upgrade-source';
    workbenchCopy(dirname(__DIR__).'/examples/reference', $newSource);
    $manifest = json_decode(file_get_contents($newSource.'/integration.json'), true, 32, JSON_THROW_ON_ERROR);
    $manifest['version'] = '1.0.1';
    file_put_contents($newSource.'/integration.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $upgradePackage = $workbench->load($newSource);
    workbenchDenied(fn () => $workbench->dispatch('connection.upgrade', [...$scope, 'execution_revision' => $upgradePackage['execution_revision']]), 'upgrade_credential_copy_confirmation_required');
    $upgrade = $workbench->dispatch('connection.upgrade', [...$scope, 'execution_revision' => $upgradePackage['execution_revision'], 'copy_credentials' => true]);
    workbenchCheck(! $upgrade['health_current'] && ! $upgrade['test_current'] && $upgrade['mappings'][$item]['revision'] === 1 && $upgrade['config'] === $fixture['configuration'], 'Upgrade retains data but invalidates evidence');
    workbenchCheck($upgrade['webhooks'][0]['uuid'] === $webhook['uuid'] && ! $upgrade['sender_overrides'][$sender]['enabled'], 'Stable endpoint and explicit override retest after upgrade');
    workbenchCheck($upgrade['resource_fixtures']['status_mapping']['shipped']['revision'] === 1, 'Upgrade retains typed resource fixtures');
    $upgradeScope = ['connection_id' => $upgrade['uuid']];
    $workbench->dispatch('connection.health', $upgradeScope);
    $workbench->dispatch('connection.test', $upgradeScope);
    $workbench->dispatch('connection.activate', $upgradeScope);
    $workbench->dispatch('override.test', [...$upgradeScope, 'sender_id' => $sender]);
    $workbench->dispatch('override.enable', [...$upgradeScope, 'sender_id' => $sender]);
    workbenchDenied(fn () => $workbench->openSession($id, 'catalog.item.mapping', $browser, $host, $item), 'active_mapping_connection_required');
    $oldSession = $workbench->openSession($id, 'sender.order.details', $browser, $host, null, $order['uuid']);
    workbenchCheck($oldSession['execution_revision'] === $package['execution_revision'], 'Existing order keeps original UI artifact');
    $newOrder = $workbench->dispatch('order.submit', [...$submit, 'connection_id' => $upgrade['uuid'], 'idempotency_key' => 'test-order-new-version']);
    $workbench->simulateCallback($newOrder['uuid'], 'Shipped');
    $workbench->simulateCallback($newOrder['uuid'], 'Delivered');
    $workbench->simulateCallback($order['uuid'], 'Delivered');
    $latest = $workbench->snapshot();
    workbenchCheck(count($latest['orders']) === 2 && array_all($latest['orders'], static fn (array $value): bool => $value['status'] === 'completed' && count($value['fulfillments']) === 1), 'Old and new orders receive callbacks under stable endpoint');
    $unknown = $workbench->dispatch('order.submit', [...$submit, 'connection_id' => $upgrade['uuid'], 'idempotency_key' => 'test-unknown', 'input' => [...$fixture['order'], 'scenario' => 'accepted_then_lost']]);
    workbenchCheck($unknown['status'] === 'queued_for_pos' && $unknown['delivery_status'] === 'unknown' && $unknown['result']['kind'] === 'unknown_remote_outcome', 'Unknown remote outcome preserves the canonical queued status');
    $reconciled = $workbench->dispatch('order.action', ['order_id' => $unknown['uuid'], 'operation' => 'orders.reconcile', 'expected_revision' => 0, 'idempotency_key' => WorkbenchState::uuid()]);
    workbenchCheck($reconciled['action']['status'] === 'completed' && $reconciled['order']['status'] === 'queued_for_pos' && $reconciled['order']['delivery_status'] === 'unknown' && $reconciled['order']['result']['kind'] === 'unknown_remote_outcome', 'Reconciliation records trusted evidence without inventing a canonical lifecycle transition');
    $setup = $workbench->openSession($upgrade['uuid'], 'connection.setup', $browser, $host);
    $current = array_find($workbench->snapshot()['connections'], static fn (array $value): bool => $value['uuid'] === $upgrade['uuid']);
    $workbench->dispatch('connection.save', [...$upgradeScope, 'expected_version' => $current['version'], 'config' => [...$fixture['configuration'], 'display_label' => 'Updated local label']]);
    workbenchDenied(fn () => $workbench->bridge($setup['session_id'], workbenchMessage($setup, 'config.read'), $browser, $host), 'bridge_session_denied');
    $reopened = new Workbench($root.'/state', $runtime);
    workbenchCheck(count($reopened->snapshot()['orders']) === 3, 'State survives process restart');
    $stateFile = file_get_contents($root.'/state/state.json');
    workbenchCheck(! str_contains($stateFile, 'private-test-api-value') && ! str_contains($stateFile, 'private-incoming-password'), 'Persisted secrets remain sealed');
    $expiredSession = $workbench->openSession($upgrade['uuid'], 'connection.setup', $browser, $host);
    (new WorkbenchState($root.'/state'))->transaction(function (array &$state) use ($expiredSession): void {
        $state['sessions'][$expiredSession['session_id']]['expires'] = time() - 1;
    });
    workbenchDenied(fn () => $workbench->bridge($expiredSession['session_id'], workbenchMessage($expiredSession, 'context.read'), $browser, $host), 'bridge_session_denied');
    mkdir($root.'/external-fixtures', 0700);
    $external = [...$fixture, 'label' => 'External local fixture', 'configuration' => [...$fixture['configuration'], 'display_label' => 'External local label']];
    file_put_contents($root.'/external-fixtures/custom.json', json_encode($external, JSON_THROW_ON_ERROR));
    $customWorkbench = new Workbench($root.'/state', $runtime, null, $root.'/external-fixtures');
    workbenchCheck($customWorkbench->snapshot()['packages'][0]['fixtures']['defaults']['label'] === 'External local fixture', 'Developer companion fixture loads without changing kit source');
    $otherFixture = [...$fixture, 'package_id' => 'acme/another-pos', 'profiles' => ['synthetic.health' => ['operations' => ['health.probe'], 'mode' => 'read', 'response' => ['vendor' => 'another']]]];
    file_put_contents($root.'/external-fixtures/another.json', json_encode($otherFixture, JSON_THROW_ON_ERROR));
    $multipleFixtures = new Workbench($root.'/state', $runtime, null, $root.'/external-fixtures');
    workbenchCheck(count($multipleFixtures->snapshot()['packages']) > 0, 'Different packages can reuse profile names without breaking workbench startup');
    $ui = StaticUi::inspect($root.'/builds/'.$package['execution_revision'], $package['manifest']);
    $asset = $workbench->asset($package['execution_revision'], $ui['sha256'], $package['manifest']['ui']['entrypoint']);
    workbenchCheck(str_contains($asset['type'], 'text/html'), 'Pinned static UI can be served');
    workbenchDenied(fn () => $workbench->asset($package['execution_revision'], str_repeat('0', 64), 'ui/index.html'), 'asset_not_found');
    echo 'Workbench workflow, bridge, callbacks, upgrades and secret isolation: '.$checks." checks passed.\n";
} finally {
    workbenchRemove($root);
}
