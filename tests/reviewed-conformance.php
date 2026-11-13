<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConformanceProfile;
use UniScript\Integration\ReviewedConformance;

$checks = 0;
$check = static function (bool $valid, string $reason) use (&$checks): void {
    $checks++;
    if (! $valid) {
        throw new RuntimeException($reason);
    }
};
$profile = ['version' => 1, 'package_id' => 'partner/new-pos', 'package_version' => '1.0.0', 'source_sha256' => str_repeat('a', 64), 'readiness_operation' => 'identity.describe', 'production_enabled' => true,
    'scenarios' => ['identity.describe' => [['input' => [], 'kind' => 'success', 'expected_calls' => []]], 'orders.submit' => [['input' => ['order_id' => 'synthetic'], 'kind' => 'remote_acceptance', 'expected_calls' => ['http.request']], ['input' => [], 'kind' => 'validation_rejection', 'expected_calls' => []]]]];
$oldEpiq = ConformanceProfile::fingerprint('uniscript/epiq-connect', '1.0.1');
$check(ReviewedConformance::validate($profile, $profile['package_id'], '1.0.0', str_repeat('a', 64), array_keys($profile['scenarios'])) === $profile, 'Exact reviewed source scope accepted');
$check(ConformanceProfile::scenarios('orders.submit', $profile['package_id'], 2, '1.0.0', null, $profile) === $profile['scenarios']['orders.submit'], 'Independent scenarios reach trusted certification');
$check(! ConformanceProfile::productionAllowed($profile['package_id'], '1.0.0'), 'Package identity alone grants no production authority');
$check(ConformanceProfile::productionAllowed($profile['package_id'], '1.0.0', null, $profile), 'Operator-reviewed exact source permits production scope');
$check(ConformanceProfile::fingerprint($profile['package_id'], '1.0.0', null, $profile) === ReviewedConformance::fingerprint($profile), 'Profile has an immutable isolated fingerprint');
$check($oldEpiq === ConformanceProfile::fingerprint('uniscript/epiq-connect', '1.0.1'), 'A new vendor never changes an existing certificate fingerprint');
$reordered = array_reverse($profile, true);
$reordered['scenarios'] = array_reverse(array_map(static fn (array $scenarios): array => array_map(static fn (array $scenario): array => array_reverse($scenario, true), $scenarios), $profile['scenarios']), true);
$check($reordered !== $profile && ReviewedConformance::fingerprint($reordered) === ReviewedConformance::fingerprint($profile), 'A store that reorders object keys never changes the fingerprint');
$changedOrder = $profile;
$changedOrder['scenarios']['orders.submit'] = array_reverse($profile['scenarios']['orders.submit']);
$check(ReviewedConformance::fingerprint($changedOrder) !== ReviewedConformance::fingerprint($profile), 'Scenario order remains part of the fingerprint');
foreach (['source', 'package', 'readiness', 'service', 'negative', 'positive', 'forged acceptance', 'object call'] as $case) {
    $changed = $profile;
    match ($case) {
        'source' => $changed['source_sha256'] = str_repeat('b', 64),
        'package' => $changed['package_id'] = 'other/vendor',
        'readiness' => $changed['scenarios']['identity.describe'][0]['expected_calls'] = ['http.request'],
        'service' => $changed['scenarios']['orders.submit'][0]['expected_calls'] = ['secrets.read'],
        'negative' => $changed['scenarios']['orders.submit'] = [$profile['scenarios']['orders.submit'][0]],
        'positive' => $changed['scenarios']['orders.submit'] = [$profile['scenarios']['orders.submit'][1]],
        'forged acceptance' => $changed['scenarios']['orders.submit'][0]['expected_calls'] = [],
        'object call' => $changed['scenarios']['orders.submit'][0]['expected_calls'] = [[]],
    };
    $denied = false;
    try {
        ReviewedConformance::validate($changed, $profile['package_id'], '1.0.0', str_repeat('a', 64));
    } catch (RuntimeException) {
        $denied = true;
    }
    $check($denied, 'Malformed or unsafe profile rejected:'.$case);
}
echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
