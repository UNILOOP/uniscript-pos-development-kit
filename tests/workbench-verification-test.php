<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

$root = sys_get_temp_dir().'/workbench-verification-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$runtime = new class(new WorkbenchFixtureRuntime($root.'/builds'))
{
    public bool $verified = false;

    public string $binding = 'reviewed-policy-1';

    public ?string $race = null;

    public array $proofCalls = [];

    public function __construct(public readonly WorkbenchFixtureRuntime $fixture) {}

    public function prepare(string $source, string $execution): array
    {
        return $this->fixture->prepare($source, $execution);
    }

    public function invoke(array $deployment, string $operation, array $input, array $config, array $credentials, array $resources, array $scope): array
    {
        if ($this->race === 'probe') {
            $this->binding = 'changed-during-probe';
        }

        return $this->fixture->invoke($deployment, $operation, $input, $config, $credentials, $resources, $scope);
    }

    public function lastEvidence(): array
    {
        return $this->fixture->lastEvidence();
    }

    public function networkMode(): array
    {
        return ['live_sandbox_enabled' => true, 'selected_package_id' => 'uniscript/reference-pos'];
    }

    public function transportBinding(string $packageId): string
    {
        return $this->binding;
    }

    public function verifyCredentials(array $deployment, array $config, array $credentials, array $scope): array
    {
        $this->proofCalls[] = compact('credentials', 'scope');
        if ($this->race === 'proof') {
            $this->binding = 'changed-during-proof';
        }

        return $this->verified && $deployment['manifest']['package_id'] === 'uniscript/reference-pos' ? ['verified' => true, 'synthetic' => false, 'profile_sha256' => str_repeat('a', 64), 'principal_fingerprint' => str_repeat('b', 64), 'account_fingerprint' => str_repeat('c', 64)] : ['verified' => false, 'simulated' => true, 'provenance' => 'offline_fixture'];
    }
};
$workbench = new Workbench($root.'/state', $runtime);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
try {
    $package = $workbench->load(dirname(__DIR__).'/examples/reference');
    $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $scope = ['connection_id' => $connection['uuid']];
    $health = $workbench->dispatch('connection.health', $scope);
    $test = $workbench->dispatch('connection.test', $scope);
    workbenchCheck(! $health['connection']['health_current'] && ! $test['connection']['test_current'], 'Static package success cannot prove live credential/account access');
    workbenchDenied(fn () => $workbench->dispatch('connection.activate', $scope), 'fresh_health_and_test_required');
    $runtime->verified = true;
    $health = $workbench->dispatch('connection.health', $scope);
    $test = $workbench->dispatch('connection.test', $scope);
    workbenchCheck($health['connection']['health_current'] && $test['connection']['test_current'], 'Independent trusted verification allows live evidence');
    workbenchCheck($runtime->proofCalls[array_key_last($runtime->proofCalls)]['credentials'] === $fixture['credentials'], 'Host verifier gets current sealed credentials');
    $workbench->dispatch('connection.activate', $scope);
    $sender = [...$scope, 'sender_id' => $workbench->snapshot()['fixtures']['sender_id']];
    $workbench->dispatch('override.save', [...$sender, 'credentials' => ['api_key' => 'sender-proof-only']]);
    $runtime->verified = false;
    $failed = $workbench->dispatch('override.test', $sender);
    workbenchCheck(! $failed['sender_overrides'][$sender['sender_id']]['tested'], 'Sender package success cannot prove live override credentials');
    workbenchDenied(fn () => $workbench->dispatch('override.enable', $sender), 'sender_override_test_required');
    $runtime->verified = true;
    $workbench->dispatch('override.test', $sender);
    workbenchCheck($runtime->proofCalls[array_key_last($runtime->proofCalls)]['credentials']['api_key'] === 'sender-proof-only' && $runtime->proofCalls[array_key_last($runtime->proofCalls)]['scope']['sender_id'] === $sender['sender_id'], 'Sender proof is scoped to its actual credential revision');
    $workbench->dispatch('override.enable', $sender);
    $runtime->race = 'probe';
    workbenchDenied(fn () => $workbench->dispatch('connection.health', $scope), 'connection_transport_profile_changed');
    workbenchCheck(! $workbench->snapshot()['connections'][0]['health_current'], 'Changed policy cannot inherit the previous probe');
    $runtime->race = null;
    $workbench->dispatch('connection.health', $scope);
    $runtime->race = 'proof';
    workbenchDenied(fn () => $workbench->dispatch('override.test', $sender), 'connection_transport_profile_changed');
    workbenchCheck(! $workbench->snapshot()['connections'][0]['sender_overrides'][$sender['sender_id']]['tested'], 'Changed verification policy cannot inherit override proof');
    $runtime->race = null;
    $workbench->dispatch('connection.health', $scope);
    $workbench->dispatch('connection.test', $scope);
    $ids = $workbench->snapshot()['fixtures'];
    $workbench->dispatch('override.remove', $sender);
    $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 0, 'values' => $fixture['mapping']]);
    $oldOrder = $workbench->dispatch('order.submit', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'sender_id' => $ids['sender_id'], 'idempotency_key' => 'pre-upgrade-live-proof', 'input' => $fixture['order']]);
    $workbench->dispatch('webhook.create', [...$scope, 'username' => 'proof-fixture', 'password' => 'independent-synthetic-password-at-least-24']);
    $duplicate = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'organization_id' => 'unrelated-pharmacy', 'location_id' => 'unrelated-location', 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $duplicateScope = ['connection_id' => $duplicate['uuid']];
    $workbench->dispatch('connection.health', $duplicateScope);
    workbenchDenied(fn () => $workbench->dispatch('connection.test', $duplicateScope), 'connection_account_already_owned');
    workbenchDenied(fn () => $workbench->dispatch('connection.activate', $duplicateScope), 'fresh_health_and_test_required');
    $nextRoot = $root.'/next-package';
    workbenchCopy(dirname(__DIR__).'/examples/reference', $nextRoot);
    $nextManifest = json_decode(file_get_contents($nextRoot.'/integration.json'), true, flags: JSON_THROW_ON_ERROR);
    $nextManifest['version'] = '1.0.1';
    file_put_contents($nextRoot.'/integration.json', json_encode($nextManifest, JSON_THROW_ON_ERROR));
    $nextPackage = $workbench->load($nextRoot);
    $next = $workbench->dispatch('connection.upgrade', [...$scope, 'execution_revision' => $nextPackage['execution_revision'], 'copy_credentials' => true]);
    $nextScope = ['connection_id' => $next['uuid']];
    $workbench->dispatch('connection.health', $nextScope);
    $workbench->dispatch('connection.test', $nextScope);
    $workbench->dispatch('connection.activate', $nextScope);
    workbenchCheck(array_find($workbench->snapshot()['connections'], static fn (array $value): bool => $value['uuid'] === $scope['connection_id'])['status'] === 'paused', 'Audited same-lineage upgrade transfers the tested account and pauses new work on the old connection');
    workbenchCheck(count(array_filter($workbench->snapshot()['events'], static fn (array $value): bool => $value['type'] === 'connection.account_transferred')) === 1, 'Account transfer leaves one explicit local audit event');
    workbenchDenied(fn () => $workbench->dispatch('connection.test', $scope), 'connection_account_already_owned');
    $drain = $workbench->dispatch('order.action', ['order_id' => $oldOrder['uuid'], 'operation' => 'orders.reconcile', 'expected_revision' => 0, 'idempotency_key' => WorkbenchState::uuid()]);
    workbenchCheck($drain['action']['status'] === 'completed' && $drain['order']['execution_revision'] === $package['execution_revision'], 'Original order outbound recovery keeps original execution and credentials after transfer');
    $workbench->simulateCallback($oldOrder['uuid'], 'Shipped');
    $workbench->simulateCallback($oldOrder['uuid'], 'Delivered');
    workbenchCheck(array_find($workbench->snapshot()['orders'], static fn (array $value): bool => $value['uuid'] === $oldOrder['uuid'])['status'] === 'completed', 'Stable endpoint still authenticates and delivers old-order callbacks after account transfer');
    $otherRoot = $root.'/other-package';
    workbenchCopy(dirname(__DIR__).'/examples/reference', $otherRoot);
    $manifest = json_decode(file_get_contents($otherRoot.'/integration.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['package_id'] = 'other/reference-pos';
    $manifest['publisher'] = 'other';
    file_put_contents($otherRoot.'/integration.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $otherPackage = $workbench->load($otherRoot);
    $other = $workbench->dispatch('connection.create', ['execution_revision' => $otherPackage['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $otherScope = ['connection_id' => $other['uuid']];
    $workbench->dispatch('connection.health', $otherScope);
    $workbench->dispatch('connection.test', $otherScope);
    $workbench->dispatch('connection.activate', $otherScope);
    workbenchCheck(count(array_filter($workbench->snapshot()['connections'], static fn (array $value): bool => $value['status'] === 'active')) === 2, 'Activating another integration does not pause an unrelated connection lineage');
    workbenchCheck(! str_contains(json_encode($workbench->snapshot()), 'sender-proof-only'), 'Verification evidence never exposes credentials');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
