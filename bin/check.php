<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

putenv('POS_KIT_LIVE_SANDBOX=0');
putenv('POS_KIT_LIVE_SANDBOX_PACKAGE');
putenv('POS_KIT_SANDBOX_PROFILES');

use UniScript\Integration\Process;

$root = dirname(__DIR__);
$tests = ['conformance-registry', 'conformance', 'canonical-conformance', 'demo-catalog', 'demo-operations', 'custom-ui-operations', 'manifest-v2', 'packaging', 'protocol-schema', 'resources', 'callbacks', 'command-proposals', 'host-runtime-client', 'local-synthetic-broker', 'mock-pos', 'static-ui', 'static-ui-http', 'developer-tools', 'documentation', 'runtime-image', 'development-runtime', 'development-sandbox-test', 'development-sandbox-http-test', 'development-sandbox-descriptor-test', 'development-connection-evidence-test'];
foreach (glob($root.'/tests/host-contract*.php') ?: [] as $file) {
    $tests[] = basename($file, '.php');
}
foreach (glob($root.'/tests/workbench*.php') ?: [] as $file) {
    if (str_contains($file, 'runtime')) {
        continue;
    }
    $tests[] = basename($file, '.php');
}
if (in_array('--docker', $argv, true)) {
    $tests = [...$tests, 'docker', 'manifest-v2-docker', 'static-ui-docker', 'resource-certification-docker', 'runtime-extensions-docker'];
    $tests[] = 'workbench-runtime-test';
    $tests[] = 'workbench-catalog-runtime-test';
    if (getenv('UNISCRIPT_LIFEFILE_PACKAGE')) {
        $tests[] = 'workbench-lifefile-runtime-test';
        $tests[] = 'workbench-lifefile-catalog-runtime-test';
    }
    foreach (glob($root.'/tests/development-runtime*-docker.php') ?: [] as $file) {
        if (str_contains($file, '-lifefile-') && ! getenv('UNISCRIPT_LIFEFILE_PACKAGE')) {
            continue;
        }
        $tests[] = basename($file, '.php');
    }
}
$results = [];
foreach (array_unique($tests) as $test) {
    $temporaryArchive = null;
    try {
        $command = [PHP_BINARY, $root.'/tests/'.$test.'.php'];
        if ($test === 'workbench-lifefile-runtime-test' || $test === 'workbench-lifefile-catalog-runtime-test') {
            $script = $test === 'workbench-lifefile-runtime-test' ? 'workbench-runtime-test.php' : 'workbench-catalog-runtime-test.php';
            $command = [PHP_BINARY, $root.'/tests/'.$script, getenv('UNISCRIPT_LIFEFILE_PACKAGE'), $root.'/fixtures/lifefile-v2.json'];
        }
        if ($test === 'development-sandbox-http-test' && in_array('--docker', $argv, true)) {
            $command[] = '--docker';
        }
        if ($test === 'custom-ui-operations') {
            $command = [PHP_BINARY, $root.'/tests/demo-operations.php', $root.'/examples/custom-ui'];
        }
        if ($test === 'static-ui-docker') {
            $temporaryArchive = tempnam(sys_get_temp_dir(), 'kit-custom-ui-');
            Process::run([PHP_BINARY, $root.'/bin/package.php', $root.'/examples/custom-ui', $temporaryArchive], 30);
            $command[] = $temporaryArchive;
        }
        $output = trim(Process::run($command, 600));
        $results[$test] = ['status' => 'passed', 'output' => $output];
        echo $test.": passed\n";
    } catch (Throwable $exception) {
        $results[$test] = ['status' => 'failed', 'error' => $exception->getMessage()];
        fwrite(STDERR, $test.": failed\n".$exception->getMessage()."\n");
    } finally {
        if (is_string($temporaryArchive) && is_file($temporaryArchive)) {
            unlink($temporaryArchive);
        }
    }
}
$failures = array_filter($results, static fn (array $result): bool => $result['status'] !== 'passed');
echo json_encode(['result' => $failures === [] ? 'passed' : 'failed', 'tests' => count($results), 'failures' => array_keys($failures), 'optional_lifefile' => getenv('UNISCRIPT_LIFEFILE_PACKAGE') ? 'requested' : 'not_requested'], JSON_THROW_ON_ERROR)."\n";
exit($failures === [] ? 0 : 1);
