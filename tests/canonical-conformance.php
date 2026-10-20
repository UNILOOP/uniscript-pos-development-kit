<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConformanceProfile;
use UniScript\Integration\Result;
use UniScript\Integration\SyntheticHost;

$assertions = 0;
$denied = static function (callable $callback, string $reason) use (&$assertions): void {
    try { $callback(); } catch (Throwable) { $assertions++; return; }
    throw new RuntimeException('expected_rejection:'.$reason);
};
$scenarios = ConformanceProfile::scenarios('orders.submit', 'uniscript/lifefile', 2, '1.0.4');
$positive = array_values(array_filter($scenarios, static fn (array $scenario): bool => isset($scenario['expected_http_projection'])))[0];
$accepted = new Result('remote_acceptance', 'synthetic.accepted', ['external_reference_handle' => 'synthetic-reference']);
$denied(static fn () => ConformanceProfile::verify('orders.submit', $positive, $accepted, 'uniscript/lifefile', []), 'success_stub_without_transport');
$denied(static fn () => ConformanceProfile::verify('orders.submit', $positive, new Result('validation_rejection', 'synthetic.rejected'), 'uniscript/lifefile', []), 'always_reject_stub');
ConformanceProfile::verify('orders.submit', $positive, $accepted, 'uniscript/lifefile', ['http.request']);
$assertions++;
$host = new SyntheticHost;
$scope = ['invocation_id' => 'synthetic-invocation'];
$grant = $host->grant($scope, ['http.request']);
$request = ['invocation_id' => $scope['invocation_id'], 'grant' => $grant, 'service' => 'http', 'action' => 'request', 'arguments' => ['profile' => 'lifefile.orders', 'operation_key' => 'orders.submit', 'body' => ['order' => ['rxs' => [['lfProductID' => 999]]]]]];
$denied(static fn () => ConformanceProfile::serviceResponse('uniscript/lifefile', $positive, $request, $host), 'wrong_vendor_product');
$negative = ['kind' => 'validation_rejection', 'expected_calls' => []];
$denied(static fn () => ConformanceProfile::verify('orders.submit', $negative, new Result('validation_rejection', 'synthetic.rejected'), 'uniscript/lifefile', ['http.request']), 'rejected_but_side_effect_called');
$legacy = ConformanceProfile::scenarios('orders.submit', 'uniscript/lifefile', 2, '1.0.3');
if (count($legacy) >= count($scenarios)) { throw new RuntimeException('legacy_profile_compatibility_lost'); }
$assertions++;
echo json_encode(['result' => 'passed', 'assertions' => $assertions], JSON_THROW_ON_ERROR)."\n";
