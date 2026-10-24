<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\HostContract;
use UniScript\Integration\Platform;
use UniScript\Integration\Process;
use UniScript\Integration\RuntimeProcess;
use UniScript\Integration\StandardRuntimeImage;

$root = dirname(__DIR__);
$directory = sys_get_temp_dir().'/kit-extension-profile-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$archive = $directory.'/source.zip';
$package = json_decode(Process::run([PHP_BINARY, $root.'/bin/package.php', $root.'/examples/starter', $archive]), true, flags: JSON_THROW_ON_ERROR);
$platform = new Platform($directory.'/platform');
$build = $platform->enqueue('builds', ['execution_uuid' => 'extensions-'.bin2hex(random_bytes(8)), 'source_sha256' => $package['source_sha256'], 'package_id' => $package['package_id'], 'package_version' => $package['version']], $archive);
$platform->work();
$build = $platform->read('builds', $build['id']);
if ($build['status'] !== 'succeeded') {
    throw new RuntimeException('Standard extension image build failed: '.($build['_diagnostic'] ?? 'unknown'));
}
$code = 'echo json_encode(["php"=>PHP_VERSION,"extensions"=>array_fill_keys(get_loaded_extensions(),true)],JSON_THROW_ON_ERROR);';
$command = ['docker', 'run', '--rm', ...RuntimeProcess::flags(), '--entrypoint', 'php', 'sha256:'.$build['digests']['executable_image_sha256'], '-r', $code];
$loaded = json_decode(Process::run($command, 30), true, flags: JSON_THROW_ON_ERROR);
if (! str_starts_with($loaded['php'], '8.4.')) {
    throw new RuntimeException('The standard package image must use PHP 8.4.');
}
$extensions = array_change_key_case($loaded['extensions'], CASE_LOWER);
foreach (HostContract::read()['runtime']['extensions'] as $extension) {
    if (! isset($extensions[strtolower($extension)])) {
        throw new RuntimeException('Advertised extension is unavailable: '.$extension);
    }
}
try {
    StandardRuntimeImage::assertAncestry(['sha256:'.str_repeat('0', 64)], StandardRuntimeImage::inspect('sha256:'.$build['digests']['executable_image_sha256']));
    throw new RuntimeException('Incorrect base ancestry was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'base_image_ancestry_mismatch') {
        throw $exception;
    }
}
echo json_encode(['result' => 'passed', 'assertions' => count(HostContract::read()['runtime']['extensions']) + 2], JSON_THROW_ON_ERROR)."\n";
