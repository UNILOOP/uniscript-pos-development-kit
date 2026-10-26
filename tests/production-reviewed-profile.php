<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConformanceProfile;
use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$root = dirname(__DIR__);
$packageSource = $argv[1] ?? getenv('UNISCRIPT_EPIQ_PACKAGE');
if (! is_string($packageSource) || ! file_exists($packageSource)) {
    throw new InvalidArgumentException('epiq_package_source_or_zip_required');
}
$checks = 0;
$check = static function (bool $condition, string $reason) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($reason);
    }
};
$denied = static function (callable $call, string|array $reasons) use ($check): void {
    try {
        $call();
    } catch (RuntimeException $error) {
        $check(in_array($error->getMessage(), (array) $reasons, true), 'Unexpected denial: '.$error->getMessage());

        return;
    }
    throw new RuntimeException('Expected denial: '.implode(', ', (array) $reasons));
};
$directory = realpath(sys_get_temp_dir()).'/uniscript-reviewed-production-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$originalRegistry = getenv('POS_KIT_CONFORMANCE_REGISTRY');
$originalBroker = getenv('HOST_SERVICES_URL');
$broker = null;
$platform = new Platform($directory.'/platform');
$write = static function (string $path, array $value): void {
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
};

try {
    $archive = $packageSource;
    if (is_dir($packageSource)) {
        $archive = $directory.'/epiq-connect.zip';
        Process::run([PHP_BINARY, $root.'/bin/package.php', $packageSource, $archive], 30);
    }
    $zip = new ZipArchive;
    $check($zip->open($archive) === true, 'Epiq source ZIP opens');
    try {
        $manifest = json_decode($zip->getFromName('integration.json'), true, 32, JSON_THROW_ON_ERROR);
    } finally {
        $zip->close();
    }
    $check($manifest['package_id'] === 'uniscript/epiq-connect', 'The real Epiq package is required');
    putenv('POS_KIT_CONFORMANCE_REGISTRY');
    $check(ConformanceProfile::productionAllowed($manifest['package_id'], $manifest['version']), 'The built-in Epiq profile explicitly permits production runtime deployment');
    $check(ConformanceProfile::productionAllowed('uniscript/lifefile-v2', '2.1.5'), 'Existing LifeFile production policy is preserved');
    $check(! ConformanceProfile::productionAllowed('acme/unreviewed', '1.0.0'), 'An unknown package has no production permission');
    $check(! ConformanceProfile::productionAllowed($manifest['package_id'], '2.0.0'), 'An unreviewed Epiq major version has no production permission');

    mkdir($directory.'/reviewed', 0700);
    $registry = json_decode(file_get_contents($root.'/fixtures/reviewed-conformance-registry.json'), true, 32, JSON_THROW_ON_ERROR);
    $profile = array_values(array_filter($registry['profiles'], static fn (array $entry): bool => $entry['package_id'] === $manifest['package_id']))[0];
    $registry = ['version' => 1, 'profiles' => [$profile]];
    $registryFile = $directory.'/reviewed/registry.json';
    $scenarioFile = $directory.'/reviewed/'.$profile['scenario_file'];
    $scenarioBytes = file_get_contents($root.'/fixtures/'.$profile['scenario_file']);
    $scenarios = json_decode($scenarioBytes, true, 32, JSON_THROW_ON_ERROR)['scenarios'];
    $write($registryFile, $registry);
    file_put_contents($scenarioFile, $scenarioBytes, LOCK_EX);
    putenv('POS_KIT_CONFORMANCE_REGISTRY='.$registryFile);

    $build = $platform->enqueue('builds', ['execution_uuid' => 'reviewed-production-'.bin2hex(random_bytes(8)), 'source_sha256' => hash_file('sha256', $archive), 'package_id' => $manifest['package_id'], 'package_version' => $manifest['version']], $archive);
    $platform->work();
    $build = $platform->read('builds', $build['id']);
    $check($build['status'] === 'succeeded', 'Real isolated Epiq build: '.json_encode($build));
    $cert = $platform->enqueue('certifications', ['execution_uuid' => $build['execution_uuid'], 'build_id' => $build['id'], 'source_sha256' => $build['source_sha256'], 'digests' => $build['digests']]);
    $platform->work();
    $cert = $platform->read('certifications', $cert['id']);
    $check($cert['status'] === 'passed', 'The real Epiq handlers pass reviewed Docker certification: '.json_encode($cert));
    $check(count($cert['certification_summary']['operations']) === count($manifest['operations']), 'Every declared Epiq operation is certified');
    $check($cert['_conformance_profile_sha256'] === ConformanceProfile::fingerprint($manifest['package_id'], $manifest['version'], $cert['_conformance_package_root']), 'Certification captures the exact operator-reviewed profile');
    $deployInput = ['deployment_uuid' => 'reviewed-production-'.bin2hex(random_bytes(8)), 'execution_uuid' => $build['execution_uuid'], 'certification_id' => $cert['id'], 'environment' => 'production', 'digests' => $build['digests']];
    $deployment = $platform->enqueue('deployments', $deployInput);
    $check($platform->enqueue('deployments', $deployInput)['id'] === $deployment['id'], 'Production deployment enqueue remains idempotent');
    $platform->work();
    $deployment = $platform->read('deployments', $deployment['id']);
    $check($deployment['status'] === 'ready', 'Reviewed Epiq production deployment becomes ready: '.json_encode($deployment));
    $inspection = json_decode(Process::run(['docker', 'inspect', $deployment['_container']], 30), true, 32, JSON_THROW_ON_ERROR)[0];
    $check($inspection['HostConfig']['ReadonlyRootfs'] === true && $inspection['HostConfig']['NetworkMode'] === 'none' && $inspection['Config']['User'] === '10001:10001' && $inspection['Mounts'] === [], 'Production readiness runs in a networkless nonroot container without host mounts');

    $scope = ['protocol_version' => '1', 'invocation_id' => 'reviewed-production-identity', 'command_id' => 'synthetic-command', 'attempt_id' => 'synthetic-attempt', 'correlation_id' => 'synthetic-correlation', 'package_id' => $manifest['package_id'], 'execution_revision' => $build['execution_uuid'], 'connection_id' => 'synthetic-connection', 'organization_id' => 'synthetic-organization', 'location_id' => 'synthetic-location', 'environment' => 'production', 'deployment_environment' => 'production', 'actor_id' => 'synthetic-actor', 'operation' => 'identity.describe', 'schema_version' => '1', 'routing_revision' => '1', 'configuration_revision' => '1', 'mapping_revision' => '1', 'credential_revision' => '1', 'idempotency_key' => 'synthetic-identity', 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 30), 'request_budget' => 0, 'input' => [], 'service_grant' => 'synthetic-no-services'];
    $check($platform->invoke($scope)->kind === 'success', 'Reviewed Epiq identity works in production runtime scope');
    $scope['environment'] = 'sandbox';
    $scope['deadline'] = gmdate('Y-m-d\TH:i:s\Z', time() + 30);
    $check($platform->invoke($scope)->kind === 'success', 'A Sandbox pharmacy connection works on the production runtime');
    $wrongScope = [...$scope, 'deployment_environment' => 'dev'];
    $denied(fn () => $platform->invoke($wrongScope), 'deployment_not_ready');
    $uncertified = [...$scope, 'operation' => 'undeclared.operation'];
    $check($platform->invoke($uncertified)->kind === 'unsupported_operation', 'Ready deployments cannot execute an uncertified handler');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $check(is_resource($socket), 'A loopback-only synthetic broker port is available');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $router = <<<'PHP'
<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo '{"ready":true}'; return; }
try {
    $request = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $fixture = json_decode(file_get_contents(__DIR__.'/broker.json'), true, 32, JSON_THROW_ON_ERROR);
    $arguments = $request['arguments'] ?? [];
    $alias = $arguments['parameters']['operation_id'] ?? '';
    $calls = is_file(__DIR__.'/calls.jsonl') ? file(__DIR__.'/calls.jsonl', FILE_IGNORE_NEW_LINES) : [];
    if (($request['invocation_id'] ?? null) !== $fixture['invocation_id'] || ! hash_equals($fixture['grant'], $request['grant'] ?? '') || ($request['service'] ?? null) !== 'http' || ($request['action'] ?? null) !== 'request'
        || ($arguments['profile'] ?? null) !== 'epiq-connect.api' || ($arguments['operation_key'] ?? null) !== $fixture['operation'] || ($fixture['sequence'][count($calls)] ?? null) !== $alias) {
        throw new RuntimeException('synthetic_broker_scope_denied');
    }
    $walk = static function (array $values) use (&$walk): void {
        foreach ($values as $key => $value) {
            if (in_array($key, ['clinicId', 'clinic_id', 'apiKey', 'api_key', 'Authorization', 'password', 'secret'], true)) { throw new RuntimeException('managed_secret_or_account_leaked'); }
            if (is_array($value)) { $walk($value); }
        }
    };
    $walk($arguments);
    file_put_contents(__DIR__.'/calls.jsonl', json_encode($request, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    echo json_encode($fixture['responses'][$alias], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(403);
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
PHP;
    file_put_contents($directory.'/router.php', $router, LOCK_EX);
    $broker = proc_open([PHP_BINARY, '-S', $address, $directory.'/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/broker.log', 'a'], 2 => ['file', $directory.'/broker.log', 'a']], $pipes, $directory);
    $check(is_resource($broker), 'The synthetic broker starts');
    $endpoint = 'http://'.$address;
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $ready = @file_get_contents($endpoint, false, stream_context_create(['http' => ['timeout' => 0.1]])) === '{"ready":true}';
        if ($ready) {
            break;
        }
        usleep(20000);
    }
    $check($ready, 'The synthetic broker is reachable');
    putenv('HOST_SERVICES_URL='.$endpoint);
    foreach (['connection.test', 'orders.submit'] as $operation) {
        $scenario = $scenarios[$operation][0];
        $scope = [...$scope, 'invocation_id' => 'reviewed-production-'.str_replace('.', '-', $operation), 'operation' => $operation, 'input' => $scenario['input'], 'request_budget' => count($scenario['expected_calls']), 'service_grant' => bin2hex(random_bytes(24)), 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 30)];
        $write($directory.'/broker.json', ['invocation_id' => $scope['invocation_id'], 'grant' => $scope['service_grant'], 'operation' => $operation, 'sequence' => $scenario['expected_http_operations'], 'responses' => $scenario['servicesByOperation']]);
        file_put_contents($directory.'/calls.jsonl', '');
        $result = $platform->invoke($scope);
        $calls = array_map(static fn (string $line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR), file($directory.'/calls.jsonl', FILE_IGNORE_NEW_LINES));
        $check($result->kind === $scenario['kind'], 'Sandbox '.$operation.' executes through the production deployment and mock broker');
        $check(array_column(array_column(array_column($calls, 'arguments'), 'parameters'), 'operation_id') === $scenario['expected_http_operations'], 'The broker sees the reviewed '.$operation.' request sequence');
        if ($operation === 'orders.submit') {
            $check(($calls[1]['arguments']['body']['prescriptions'][0]['isAutoFlow'] ?? null) === true && ($calls[1]['arguments']['parameters']['patient_reference_handle'] ?? null) === 'synthetic-patient-handle', 'Canonical submission preserves autoflow and an opaque host-owned patient reference');
            $check(($result->data['external_reference_handle'] ?? null) === 'synthetic-order-handle', 'Submission returns the synthetic host-owned order reference');
        }
    }

    $identity = [...$scope, 'operation' => 'identity.describe', 'input' => [], 'request_budget' => 0, 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 30)];
    $queued = $platform->enqueue('deployments', [...$deployInput, 'deployment_uuid' => 'stale-profile-'.bin2hex(random_bytes(8))]);
    file_put_contents($scenarioFile, $scenarioBytes."\n", LOCK_EX);
    $denied(fn () => $platform->invoke($identity), 'conformance_profile_changed_after_certification');
    $denied(fn () => $platform->enqueue('deployments', [...$deployInput, 'deployment_uuid' => 'stale-enqueue-'.bin2hex(random_bytes(8))]), 'conformance_profile_changed_after_certification');
    $platform->work();
    $queued = $platform->read('deployments', $queued['id']);
    $check($queued['status'] === 'failed' && str_contains($queued['_diagnostic'] ?? '', 'conformance_profile_changed_after_certification'), 'A queued production deployment cannot become ready after its reviewed profile changes');
    file_put_contents($scenarioFile, $scenarioBytes, LOCK_EX);

    $revoked = $registry;
    $revoked['profiles'][0]['production_enabled'] = false;
    $write($registryFile, $revoked);
    $check(! ConformanceProfile::productionAllowed($manifest['package_id'], $manifest['version'], $cert['_conformance_package_root']), 'Revoking production permission denies the reviewed package');
    $denied(fn () => $platform->enqueue('deployments', [...$deployInput, 'deployment_uuid' => 'revoked-profile-'.bin2hex(random_bytes(8))]), 'conformance_profile_changed_after_certification');
    $denied(fn () => $platform->invoke($identity), 'conformance_profile_changed_after_certification');
    unset($revoked['profiles'][0]['production_enabled']);
    $write($registryFile, $revoked);
    $check(! ConformanceProfile::productionAllowed($manifest['package_id'], $manifest['version'], $cert['_conformance_package_root']), 'A reviewed profile without explicit production permission stays disabled');
    $disabledBuild = $platform->enqueue('builds', ['execution_uuid' => 'disabled-production-'.bin2hex(random_bytes(8)), 'source_sha256' => hash_file('sha256', $archive), 'package_id' => $manifest['package_id'], 'package_version' => $manifest['version']], $archive);
    $platform->work();
    $disabledBuild = $platform->read('builds', $disabledBuild['id']);
    $check($disabledBuild['status'] === 'succeeded', 'A package can build without production permission');
    $disabledCert = $platform->enqueue('certifications', ['execution_uuid' => $disabledBuild['execution_uuid'], 'build_id' => $disabledBuild['id'], 'source_sha256' => $disabledBuild['source_sha256'], 'digests' => $disabledBuild['digests']]);
    $platform->work();
    $disabledCert = $platform->read('certifications', $disabledCert['id']);
    $check($disabledCert['status'] === 'passed', 'Reviewing and certifying handlers does not imply production permission');
    $disabledInput = ['deployment_uuid' => 'disabled-production-'.bin2hex(random_bytes(8)), 'execution_uuid' => $disabledBuild['execution_uuid'], 'certification_id' => $disabledCert['id'], 'environment' => 'production', 'digests' => $disabledBuild['digests']];
    $denied(fn () => $platform->enqueue('deployments', $disabledInput), 'environment_not_enabled');
    $local = $platform->enqueue('deployments', [...$disabledInput, 'deployment_uuid' => 'disabled-local-'.bin2hex(random_bytes(8)), 'environment' => 'local']);
    $platform->work();
    $local = $platform->read('deployments', $local['id']);
    $check($local['status'] === 'ready', 'A production-disabled reviewed package can still become ready locally');
    $localScope = [...$identity, 'execution_revision' => $disabledBuild['execution_uuid'], 'environment' => 'production', 'deployment_environment' => 'local', 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 30)];
    $denied(fn () => $platform->invoke($localScope), 'production_not_enabled');
    $localScope['environment'] = 'sandbox';
    $check($platform->invoke($localScope)->kind === 'success', 'The same reviewed package remains usable with a Sandbox connection locally');
    $write($registryFile, $registry);

    $identity['deadline'] = gmdate('Y-m-d\TH:i:s\Z', time() + 30);
    foreach (['source_sha256' => str_repeat('0', 64), 'executable_image_sha256' => str_repeat('0', 64), '_operations' => ['identity.describe']] as $field => $value) {
        $platform->save('deployments', [...$deployment, $field => $value]);
        try {
            $denied(fn () => $platform->invoke($identity), 'deployment_binding_mismatch');
        } finally {
            $platform->save('deployments', $deployment);
        }
    }
    $copiedDev = [...$deployment, 'id' => bin2hex(random_bytes(16)), 'environment' => 'dev'];
    $platform->save('deployments', $copiedDev);
    $productionOnDev = [...$identity, 'environment' => 'production', 'deployment_environment' => 'dev'];
    $denied(fn () => $platform->invoke($productionOnDev), 'production_not_enabled');

    $platform->save('certifications', [...$cert, 'status' => 'failed']);
    $denied(fn () => $platform->invoke($identity), 'deployment_binding_mismatch');
    $platform->save('certifications', $cert);
    $platform->save('certifications', [...$cert, '_package_version' => '2.0.0']);
    $denied(fn () => $platform->invoke($identity), 'deployment_binding_mismatch');
    $platform->save('certifications', $cert);
    $identity['deadline'] = gmdate('Y-m-d\TH:i:s\Z', time() + 30);
    $check($platform->invoke($identity)->kind === 'success', 'Restoring the original certified profile and readiness binding restores the original deployment');
    echo json_encode(['result' => 'passed', 'checks' => $checks, 'package_id' => $manifest['package_id'], 'source_sha256' => $build['source_sha256'], 'image_sha256' => $build['digests']['executable_image_sha256'], 'deployment_environment' => 'production', 'connection_environment' => 'sandbox', 'vendor_transport' => 'loopback synthetic broker only'], JSON_THROW_ON_ERROR)."\n";
} finally {
    foreach (['POS_KIT_CONFORMANCE_REGISTRY' => $originalRegistry, 'HOST_SERVICES_URL' => $originalBroker] as $name => $value) {
        putenv($value === false ? $name : $name.'='.$value);
    }
    if (is_resource($broker)) {
        proc_terminate($broker);
        proc_close($broker);
    }
    foreach (glob($platform->state.'/deployments/*.json') ?: [] as $file) {
        $record = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (is_string($record['_container'] ?? null)) {
            Process::run(['docker', 'rm', '-f', $record['_container']], 30);
        }
    }
}
