<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Process;

$assertions = 0;
function check(bool $condition, string $reason): void
{
    global $assertions;
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($reason);
    }
}
$root = sys_get_temp_dir().'/kit-packaging-'.bin2hex(random_bytes(6));
$package = $root.'/package';
mkdir($package.'/src', 0700, true);
mkdir($package.'/schemas', 0700);
mkdir($package.'/resources', 0700);
mkdir($package.'/.git/hooks', 0700, true);
foreach (['integration.json', 'composer.json', 'composer.lock'] as $file) {
    copy(dirname(__DIR__).'/examples/starter/'.$file, $package.'/'.$file);
}copy(dirname(__DIR__).'/examples/starter/src/runtime.php', $package.'/src/runtime.php');
foreach (['input.json', 'output.json'] as $file) {
    copy(dirname(__DIR__).'/examples/starter/schemas/'.$file, $package.'/schemas/'.$file);
}
file_put_contents($package.'/.git/config', 'VCS-PRIVATE-MARKER');
file_put_contents($package.'/.git/hooks/pre-commit', 'do not package hooks');
file_put_contents($package.'/.gitignore', "/dist\n");
file_put_contents($package.'/.gitattributes', '* text=auto');
foreach (['tests', 'fixtures', 'dist', 'dev-fixtures', '.github', 'node_modules'] as $development) {
    mkdir($package.'/'.$development, 0700);
    file_put_contents($package.'/'.$development.'/local.json', 'DEVELOPMENT-ONLY-MARKER');
}
file_put_contents($package.'/.editorconfig', 'root=true');
for ($index = 0; $index < 40; $index++) {
    file_put_contents($package.'/resources/small-'.$index.'.txt', 'tiny synthetic fixture');
}
$zip = $root.'/source.zip';
$first = json_decode(Process::run([PHP_BINARY, dirname(__DIR__).'/bin/package.php', $package, $zip]), true);
check($first['file_count'] === 46, 'VCS and development metadata omitted');
$archive = new ZipArchive;
$archive->open($zip);
for ($index = 0; $index < $archive->numFiles; $index++) {
    check(! str_contains((string) $archive->getFromIndex($index), 'DEVELOPMENT-ONLY-MARKER') && ! str_starts_with($archive->getNameIndex($index), '.git'), 'no development source in ZIP');
}$archive->close();
file_put_contents($package.'/.git/config', 'CHANGED-VCS-PRIVATE-MARKER');
$second = json_decode(Process::run([PHP_BINARY, dirname(__DIR__).'/bin/package.php', $package, $root.'/second.zip']), true);
check($first['source_sha256'] === $second['source_sha256'], 'VCS changes do not affect deterministic source');
$reader = $root.'/read.php';
file_put_contents($reader, '<?php require '.var_export(dirname(__DIR__).'/bootstrap.php', true).'; UniScript\\Integration\\Archive::unpack($argv[1],$argv[2],$argv[3]);echo json_encode(["peak"=>memory_get_peak_usage(true)]);');
$read = json_decode(Process::run([PHP_BINARY, '-d', 'memory_limit=24M', $reader, $zip, $root.'/unpacked', $first['source_sha256']]), true);
check($read['peak'] < 24 * 1024 * 1024, 'many tiny ZIP entries do not allocate eight MiB each');
file_put_contents($package.'/.env', 'SECRET-MUST-NOT-PACKAGE');
$rejected = false;
try {
    Process::run([PHP_BINARY, dirname(__DIR__).'/bin/package.php', $package, $root.'/forbidden.zip']);
} catch (RuntimeException) {
    $rejected = true;
}check($rejected, 'secret file still rejected');
echo json_encode(['result' => 'passed', 'assertions' => $assertions], JSON_THROW_ON_ERROR)."\n";
