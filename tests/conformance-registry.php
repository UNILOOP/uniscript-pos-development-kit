<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConformanceProfile;
use UniScript\Integration\Result;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$denied = static function (callable $call, string $reason) use ($check): void {
    try {
        $call();
    } catch (RuntimeException $error) {
        $check($error->getMessage() === $reason, 'Expected '.$reason.', got '.$error->getMessage());

        return;
    }
    throw new RuntimeException('Expected '.$reason);
};
$original = getenv('POS_KIT_CONFORMANCE_REGISTRY');
putenv('POS_KIT_CONFORMANCE_REGISTRY');
$root = realpath(sys_get_temp_dir()).'/conformance-registry-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root.'/package', 0700);
try {
    $check(ConformanceProfile::readinessOperation('uniscript/epiq-connect', '1.0.0') === 'identity.describe', 'Readiness needs no credentials or HTTP grant');
    $check(strlen(ConformanceProfile::fingerprint('uniscript/epiq-connect', '1.0.0')) === 64, 'Reviewed profile has an immutable fingerprint');
    foreach (['identity.describe', 'configuration.validate', 'health.probe', 'connection.test', 'orders.validate', 'orders.submit', 'orders.reconcile', 'epiq.prescriptions.stage', 'epiq.api.execute', 'reference.refresh', 'catalog.preview', 'inbound.decode'] as $operation) {
        $check(ConformanceProfile::scenarios($operation, 'uniscript/epiq-connect', 2, '1.0.0') !== [], 'Reviewed handler coverage '.$operation);
    }
    $denied(fn () => ConformanceProfile::scenarios('undeclared.operation', 'uniscript/epiq-connect', 2, '1.0.0'), 'trusted_scenario_not_available:undeclared.operation');
    $profile = ['package_id' => 'acme/vendor', 'min_version' => '1.0.0', 'max_version_exclusive' => '2.0.0', 'scenario_file' => 'scenarios.json', 'readiness_operation' => 'identity.describe'];
    $registry = ['version' => 1, 'profiles' => [$profile]];
    $scenarios = ['package_id' => 'acme/vendor', 'scenarios' => ['identity.describe' => [['input' => [], 'kind' => 'success']]]];
    $save = static function (array $value, string $filename) use ($root): void {
        file_put_contents($root.'/'.$filename, json_encode($value, JSON_THROW_ON_ERROR));
    };
    $save($registry, 'registry.json');
    $save($scenarios, 'scenarios.json');
    putenv('POS_KIT_CONFORMANCE_REGISTRY='.$root.'/registry.json');
    $check(ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.2.3', $root.'/package')[0]['kind'] === 'success', 'Operator-owned generic profile works');
    $fingerprint = ConformanceProfile::fingerprint('acme/vendor', '1.2.3', $root.'/package');
    file_put_contents($root.'/scenarios.json', "\n", FILE_APPEND);
    $check($fingerprint !== ConformanceProfile::fingerprint('acme/vendor', '1.2.3', $root.'/package'), 'Changing reviewed scenario bytes invalidates certification binding');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/unknown', 2, '1.0.0', $root.'/package'), 'conformance_profile_not_registered');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '2.0.0', $root.'/package'), 'conformance_profile_not_registered');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.0.0'), 'conformance_package_root_required');
    $scenarios['package_id'] = 'acme/other';
    $save($scenarios, 'scenarios.json');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.0.0', $root.'/package'), 'conformance_scenarios_invalid');
    $scenarios['package_id'] = 'acme/vendor';
    $save($scenarios, 'scenarios.json');
    $registry['profiles'][0]['scenario_file'] = '../scenarios.json';
    $save($registry, 'registry.json');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.0.0', $root.'/package'), 'conformance_profile_invalid');
    $registry['profiles'][0] = $profile;
    $save($registry, 'registry.json');
    rename($root.'/scenarios.json', $root.'/target.json');
    symlink($root.'/target.json', $root.'/scenarios.json');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.0.0', $root.'/package'), 'conformance_profile_path_denied');
    unlink($root.'/scenarios.json');
    rename($root.'/target.json', $root.'/scenarios.json');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.0.0', $root), 'conformance_profile_path_denied');
    $registry['profiles'][] = $profile;
    $save($registry, 'registry.json');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.0.0', $root.'/package'), 'conformance_profile_ambiguous');
    file_put_contents($root.'/registry.json', '{malformed');
    $denied(fn () => ConformanceProfile::scenarios('identity.describe', 'acme/vendor', 2, '1.0.0', $root.'/package'), 'conformance_registry_invalid');
    putenv('POS_KIT_CONFORMANCE_REGISTRY');
    $scenario = ConformanceProfile::scenarios('reference.refresh', 'uniscript/epiq-connect', 2, '1.0.0')[0];
    $result = new Result('success', 'epiq.api_success', ['operation_id' => 'listPrescribers']);
    ConformanceProfile::verify('reference.refresh', $scenario, $result, 'uniscript/epiq-connect', ['http.request'], [['service' => 'http', 'action' => 'request', 'arguments' => ['parameters' => ['operation_id' => 'listPrescribers']]]]);
    $check(true, 'Vendor read is verified without inventing a CAS revision');
    $denied(fn () => ConformanceProfile::verify('reference.refresh', $scenario, $result, 'uniscript/epiq-connect', ['http.request'], [['service' => 'http', 'action' => 'request', 'arguments' => ['parameters' => ['operation_id' => 'other']]]]), 'conformance_http_operation_sequence:reference.refresh');
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    if ($original === false) {
        putenv('POS_KIT_CONFORMANCE_REGISTRY');
    } else {
        putenv('POS_KIT_CONFORMANCE_REGISTRY='.$original);
    }
    foreach (glob($root.'/*') ?: [] as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }
    rmdir($root.'/package');
    rmdir($root);
}
