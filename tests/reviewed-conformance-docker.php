<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConformanceProfile;
use UniScript\Integration\Platform;
use UniScript\Integration\Process;
use UniScript\Integration\ReviewedConformance;

$kit = dirname(__DIR__);
$state = sys_get_temp_dir().'/generic-reviewed-package-'.bin2hex(random_bytes(8));
mkdir($state, 0700);
$source = $state.'/package';
mkdir($source, 0700);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kit.'/examples/demo', FilesystemIterator::SKIP_DOTS)) as $file) {
    $target = $source.substr($file->getPathname(), strlen($kit.'/examples/demo'));
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    copy($file->getPathname(), $target);
}
$manifest = json_decode(file_get_contents($source.'/integration.json'), true, 32, JSON_THROW_ON_ERROR);
$manifest['package_id'] = 'partner/portable-integration';
$manifest['publisher'] = 'partner';
$manifest['integration_key'] = 'portable-integration';
file_put_contents($source.'/integration.json', json_encode($manifest, JSON_THROW_ON_ERROR));
$composer = json_decode(file_get_contents($source.'/composer.json'), true, 32, JSON_THROW_ON_ERROR);
$composer['name'] = $manifest['package_id'];
file_put_contents($source.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
$lock = json_decode(file_get_contents($source.'/composer.lock'), true, 32, JSON_THROW_ON_ERROR);
$relevant = array_intersect_key($composer, array_flip(['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
ksort($relevant);
$lock['content-hash'] = md5(json_encode($relevant, JSON_THROW_ON_ERROR));
file_put_contents($source.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
$archive = $state.'/source.zip';
Process::run([PHP_BINARY, $kit.'/bin/package.php', $source, $archive]);
$scenarios = [];
foreach ($manifest['operations'] as $operation) {
    $key = $operation['key'];
    $scenarios[$key] = ConformanceProfile::scenarios($key, 'uniscript/demo', 2, '1.0.3');
    foreach ($scenarios[$key] as &$scenario) {
        $scenario['expected_calls'] ??= match ($key) {
            'orders.submit', 'orders.reconcile' => $scenario['kind'] === 'remote_acceptance' ? ['http.request'] : [],
            'reference.refresh' => ['storage.write'],
            default => [],
        };
    }
    unset($scenario);
}
$profile = ['version' => 1, 'package_id' => $manifest['package_id'], 'package_version' => $manifest['version'], 'source_sha256' => hash_file('sha256', $archive), 'readiness_operation' => 'identity.describe', 'production_enabled' => true, 'scenarios' => $scenarios];
ReviewedConformance::validate($profile, $manifest['package_id'], $manifest['version'], $profile['source_sha256'], array_column($manifest['operations'], 'key'));
$checks = 0;
$check = static function (bool $valid, string $reason) use (&$checks): void {
    $checks++;
    if (! $valid) {
        throw new RuntimeException($reason);
    }
};
$originalEpiq = ConformanceProfile::fingerprint('uniscript/epiq-connect', '1.0.1');
$check(! ConformanceProfile::productionAllowed($manifest['package_id'], $manifest['version']), 'Unknown package cannot self-authorize production');
$check(ConformanceProfile::productionAllowed($manifest['package_id'], $manifest['version'], $source, $profile), 'Reviewed exact source profile authorizes certification scope');
$check($originalEpiq === ConformanceProfile::fingerprint('uniscript/epiq-connect', '1.0.1'), 'Adding a release profile cannot invalidate existing Epiq certificates');
$platform = new Platform($state.'/platform');
$build = $platform->enqueue('builds', ['execution_uuid' => 'generic-review-'.bin2hex(random_bytes(8)), 'source_sha256' => $profile['source_sha256'], 'package_id' => $manifest['package_id'], 'package_version' => $manifest['version']], $archive);
$platform->work();
$build = $platform->read('builds', $build['id']);
$check($build['status'] === 'succeeded', 'Generic source build failed:'.($build['reason_code'] ?? 'unknown'));
$cert = $platform->enqueue('certifications', ['execution_uuid' => $build['execution_uuid'], 'build_id' => $build['id'], 'source_sha256' => $profile['source_sha256'], 'digests' => $build['digests'], 'reviewed_profile' => $profile]);
$platform->work();
$cert = $platform->read('certifications', $cert['id']);
$check($cert['status'] === 'passed', 'Independent generic certification failed:'.($cert['reason_code'] ?? 'unknown'));
$check($cert['reviewed_profile_sha256'] === ReviewedConformance::fingerprint($profile), 'Certification must pin the reviewed profile');
$check(count($cert['certification_summary']['operations']) === count($manifest['operations']), 'All handlers independently exercised');
$deployment = $platform->enqueue('deployments', ['deployment_uuid' => 'generic-local-production-'.bin2hex(random_bytes(8)), 'execution_uuid' => $build['execution_uuid'], 'certification_id' => $cert['id'], 'environment' => 'production', 'digests' => $build['digests']]);
$platform->work();
$deployment = $platform->read('deployments', $deployment['id']);
try {
    $check($deployment['status'] === 'ready', 'Local synthetic production-scope deployment failed:'.($deployment['reason_code'] ?? 'unknown'));
    $envelope = ['protocol_version' => '1', 'invocation_id' => 'generic-reviewed-invoke', 'command_id' => 'generic-reviewed-command', 'attempt_id' => 'generic-reviewed-attempt', 'correlation_id' => 'generic-reviewed-correlation', 'package_id' => $manifest['package_id'], 'execution_revision' => $build['execution_uuid'], 'connection_id' => 'synthetic-connection', 'organization_id' => 'synthetic-organization', 'location_id' => 'synthetic-location', 'environment' => 'production', 'deployment_environment' => 'production', 'actor_id' => 'synthetic-actor', 'operation' => 'identity.describe', 'schema_version' => '1', 'routing_revision' => '1', 'configuration_revision' => '1', 'mapping_revision' => '1', 'credential_revision' => '1', 'idempotency_key' => 'generic-reviewed-runtime', 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 30), 'request_budget' => 0, 'input' => [], 'service_grant' => 'synthetic-no-services'];
    $result = $platform->invoke($envelope);
    $check($result->kind === 'success' && $result->data['package_id'] === $manifest['package_id'], 'Runtime reuses the exact reviewed production-scope certificate');
    $check(Platform::publicRecord($cert)['reviewed_profile_sha256'] === $cert['reviewed_profile_sha256'] && ! isset(Platform::publicRecord($cert)['_reviewed_profile']), 'Only the fingerprint leaves the trusted certifier');
} finally {
    if (isset($deployment['_container'])) {
        Process::run(['docker', 'rm', '-f', $deployment['_container']]);
    }
}
echo json_encode(['result' => 'passed', 'checks' => $checks, 'package_id' => $manifest['package_id'], 'scope' => 'isolated local synthetic production-scope deployment; no AWS or live vendor traffic'], JSON_THROW_ON_ERROR)."\n";
