<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\HostContract;
use UniScript\Integration\HostRequirements;
use UniScript\Integration\JsonDocument;

$assertions = 0;
$check = static function (bool $condition, string $label) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('failed:'.$label);
    }
};
$reject = static function (callable $call, string $label) use ($check): void {
    try {
        $call();
    } catch (InvalidArgumentException) {
        $check(true, $label);
        return;
    }
    $check(false, $label);
};
$contract = HostContract::read();
HostContract::assertCompatible($contract);
$check(strlen(HostContract::digest()) === 64, 'stable contract digest');
$check(HostContract::surface('sender.order.details')['actions'] === [], 'sender has no mutations');
$check(HostContract::surface('connection.setup')['actions'] === ['config.save'], 'only declared setup mutation');
$check(!in_array('credentials.read', HostContract::operations(), true), 'credentials stay host owned');
$check(hash_file('sha256', dirname(__DIR__).'/ui/bridge.ts') === $contract['ui']['bridge_sha256'], 'bridge source matches actual host export');
$reject(static fn () => HostContract::surface('invented.screen'), 'unknown surface denied');
foreach ([
    ['contract_version' => '2.0.0'],
    ['unexpected' => 'field'],
    ['ui' => ['credential_controls_host_owned' => false]],
    ['ui' => ['surfaces' => ['sender.order.details' => ['actions' => ['config.save']]]]],
    ['ui' => ['surfaces' => ['connection.setup' => ['queries' => ['context.read', 'context.read']]]]],
    ['onboarding' => ['local_scenarios_authorize_vendor_access' => true]],
] as $patch) {
    $changed = array_replace_recursive($contract, $patch);
    $reject(static fn () => HostContract::assertCompatible($changed), 'incompatible or unsafe contract rejected');
}
$changed = $contract;
$changed['limits']['ui_bytes']++;
$reject(static fn () => HostContract::assertCompatible($changed), 'semantic drift denied');
$fixtures = JsonDocument::decode(file_get_contents(dirname(__DIR__).'/contracts/projection-examples.json'), true);
foreach ($fixtures as $operation => $fixture) {
    HostContract::assertProjection($operation, $fixture);
    $check(true, 'documented projection matches supported schema');
    $unsafe = [...$fixture, 'credentials' => ['password' => 'synthetic-private']];
    $reject(static fn () => HostContract::assertProjection($operation, $unsafe), 'extra private projection field denied');
}
$unsafe = $fixtures['order.read'];
$unsafe['fulfillments'][0]['tracking_number'] = 'private-synthetic-tracking';
$reject(static fn () => HostContract::assertProjection('order.read', $unsafe), 'raw tracking never enters package projection');
$check(count(HostContract::timezones()) > 400, 'core timezone reference catalog retained');
$requirements = ['contract_version' => 1, 'transport_profiles' => ['vendor.orders'], 'verification_profile' => null, 'inbound_profiles' => []];
$check(HostRequirements::parse(json_encode($requirements, JSON_THROW_ON_ERROR)) === $requirements, 'profile requests remain names only');
$reject(static fn () => HostRequirements::parse(json_encode([...$requirements, 'secret' => 'forbidden'], JSON_THROW_ON_ERROR)), 'profile requests cannot grant credentials or definitions');
$reject(static fn () => HostRequirements::parse(json_encode([...$requirements, 'transport_profiles' => ['https://attacker.example']], JSON_THROW_ON_ERROR)), 'profile requests cannot select URLs');
echo json_encode(['result' => 'passed', 'assertions' => $assertions], JSON_THROW_ON_ERROR)."\n";
