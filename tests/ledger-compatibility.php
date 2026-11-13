<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\ConformanceProfile;
use UniScript\Integration\Platform;
use UniScript\Integration\Process;

$checks = 0;
$check = static function (bool $valid, string $reason) use (&$checks): void {
    $checks++;
    if (! $valid) {
        throw new RuntimeException($reason);
    }
};

// Certificates already issued by deployed platforms carry these values. A kit change that alters one of them would
// refuse every live production invocation of that package, so the values are pinned rather than recomputed.
$check(ConformanceProfile::fingerprint('uniscript/epiq-connect', '1.0.1') === '58de42302965964b12228eb0b6ae1db2fb575842c473552517a84545c318ce4f', 'The Epiq certificate fingerprint issued to live deployments must not change');
$check(ConformanceProfile::fingerprint('uniscript/lifefile-v2', '2.1.5') === null, 'LifeFile v2 certificates stay bound to the built-in profile without a registry fingerprint');

$state = sys_get_temp_dir().'/kit-ledger-'.bin2hex(random_bytes(6));
try {
    $platform = new Platform($state);
    $digests = array_fill_keys(['lockfile_sha256', 'dependencies_sha256', 'sdk_sha256', 'supervisor_sha256', 'base_image_sha256', 'executable_image_sha256', 'ui_sha256'], str_repeat('a', 64));
    $operations = ['identity.describe', 'orders.submit'];
    $certificate = static fn (string $id, string $package, string $version, ?string $fingerprint, array $extra = []): array => ['id' => $id, 'status' => 'passed', 'execution_uuid' => 'execution-'.$id, 'source_sha256' => str_repeat('b', 64), 'digests' => $digests,
        'certification_summary' => ['operations' => $operations], '_package_id' => $package, '_package_version' => $version, '_conformance_profile_sha256' => $fingerprint, ...$extra];
    $deployment = static fn (string $id, array $cert, string $environment, array $extra = []): array => ['id' => $id, 'status' => 'ready', 'execution_uuid' => $cert['execution_uuid'], 'environment' => $environment, 'digests' => $digests, 'source_sha256' => $cert['source_sha256'],
        'executable_image_sha256' => $digests['executable_image_sha256'], '_container' => 'uniscript-runtime-'.$id, '_package_id' => $cert['_package_id'], '_operations' => $operations, '_package_version' => $cert['_package_version'],
        '_conformance_profile_sha256' => $cert['_conformance_profile_sha256'], '_input' => ['certification_id' => $cert['id']], ...$extra];
    $id = static fn (int $number): string => str_pad((string) $number, 32, '0', STR_PAD_LEFT);

    $epiq = $certificate($id(1), 'uniscript/epiq-connect', '1.0.1', ConformanceProfile::fingerprint('uniscript/epiq-connect', '1.0.1'));
    $lifefile = $certificate($id(2), 'uniscript/lifefile-v2', '2.1.5', null);
    $earlier = $certificate($id(3), 'uniscript/lifefile-v2', '2.1.4', null);
    $legacy = $deployment($id(14), $earlier, 'production');
    unset($earlier['_package_version'], $earlier['_conformance_profile_sha256'], $legacy['_package_version'], $legacy['_conformance_profile_sha256']);
    foreach ([$epiq, $lifefile, $earlier] as $cert) {
        $platform->save('certifications', $cert);
    }
    $platform->save('deployments', $deployment($id(11), $epiq, 'production'));
    $platform->save('deployments', $deployment($id(12), $lifefile, 'production'));
    $platform->save('deployments', $deployment($id(13), $epiq, 'sandbox'));
    $platform->save('deployments', $legacy);
    $check($platform->ledgerIncompatibilities() === [], 'Live Epiq and LifeFile deployments, including records written before fingerprints existed, remain invocable');

    $changed = $certificate($id(4), 'uniscript/epiq-connect', '1.0.1', str_repeat('0', 64));
    $platform->save('certifications', $changed);
    $platform->save('deployments', $deployment($id(15), $changed, 'production'));
    $platform->save('deployments', $deployment($id(16), $epiq, 'sandbox', ['_operations' => null]));
    $platform->save('deployments', $deployment($id(17), $changed, 'production', ['status' => 'failed']));
    $refused = $platform->ledgerIncompatibilities();
    $check(array_column($refused, 'reason', 'deployment') === [$id(15) => 'conformance_profile_changed_after_certification', $id(16) => 'deployment_record_incomplete'], 'Only ready deployments this kit would refuse are reported, each with its reason');
    $check($refused[0]['package_id'] === 'uniscript/epiq-connect' && $refused[0]['environment'] === 'production', 'A refusal names the package and environment it affects');

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/verify-ledger.php'], $descriptors, $pipes, null, [...getenv(), 'PLATFORM_STATE' => $state]);
    $output = json_decode((string) stream_get_contents($pipes[1]), true);
    $check(proc_close($process) === 1 && ($output['result'] ?? null) === 'incompatible' && count($output['refused']) === 2, 'The rollout check exits non-zero and lists refusals before a kit is switched in');
    unlink($state.'/deployments/'.$id(15).'.json');
    unlink($state.'/deployments/'.$id(16).'.json');
    putenv('PLATFORM_STATE='.$state);
    $check(str_contains(Process::run([PHP_BINARY, dirname(__DIR__).'/bin/verify-ledger.php'], 30), '"compatible"'), 'A compatible ledger passes the rollout check');
} finally {
    Process::run(['rm', '-rf', $state], 10);
}
echo json_encode(['result' => 'passed', 'checks' => $checks])."\n";
