<?php

declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Manifest;
use UniScript\Integration\StaticUi;

$count = 0;
function check(bool $ok): void
{
    global $count;
    $count++;
    if (! $ok) {
        throw new RuntimeException('assertion_failed_'.$count);
    }
}
$root = dirname(__DIR__).'/examples/custom-ui';
$manifest = Manifest::read($root);
$ui = StaticUi::inspect($root, $manifest);
check($manifest['version'] === '1.0.3');
check(count($ui['files']) === 4);
check(strlen($ui['sha256']) === 64);
$files = $ui['files'];
ksort($files, SORT_STRING);
check($ui['sha256'] === hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)));
check(StaticUi::inspect(dirname(__DIR__).'/examples/demo', Manifest::read(dirname(__DIR__).'/examples/demo'))['sha256'] === hash('sha256', ''));
foreach ([['profile' => 'static-v1', 'entrypoint' => 'ui/../index.html'], ['profile' => 'static-v1', 'entrypoint' => 'ui/server.php'], ['profile' => 'static-v1', 'entrypoint' => 'ui/index.html', 'url' => 'https://unsafe.invalid'], ['profile' => 'unknown', 'entrypoint' => 'ui/index.html']] as $definition) {
    try {
        StaticUi::inspect($root, [...$manifest, 'ui' => $definition]);
        throw new RuntimeException('invalid_ui_accepted');
    } catch (InvalidArgumentException) {
        check(true);
    }
}
$surfaces = ['connection.setup', 'catalog.item.mapping', 'pharmacy.order.details', 'sender.order.details'];
check(StaticUi::inspect($root, [...$manifest, 'ui' => [...$manifest['ui'], 'surfaces' => $surfaces]]) === $ui);
foreach ([[], ['internal.admin'], ['connection.setup', 'connection.setup'], ['setup' => 'connection.setup'], 'connection.setup', null, [12], [['connection.setup']]] as $invalid) {
    try {
        StaticUi::inspect($root, [...$manifest, 'ui' => [...$manifest['ui'], 'surfaces' => $invalid]]);
        throw new RuntimeException('invalid_surface_accepted');
    } catch (InvalidArgumentException) {
        check(true);
    }
}
$old = $manifest;
$old['manifest_version'] = 1;
try {
    StaticUi::inspect($root, $old);
    throw new RuntimeException('v1_ui_accepted');
} catch (InvalidArgumentException) {
    check(true);
}
foreach (['ui/index.html', 'ui/assets/screen.abc123.js', 'ui/assets/theme.123abc.css', 'ui/release.v1/screen.hash.html', 'ui/'.str_repeat('a', 234).'.js'] as $path) {
    check(StaticUi::isPath($path));
    StaticUi::assertPath($path);
}
foreach (['ui/server.php', 'ui/icon.svg', 'ui/../index.html', 'ui/.hidden.js', 'ui/assets/.screen.js', 'ui/assets/screen..hash.js', 'ui/assets./screen.js', 'ui/%2e%2e/screen.js', 'ui/assets/screen.hash.js?token=x', 'ui/assets/screen.hash.js#fragment', 'ui/assets/screen.hash.JS', 'ui/assets/screen.hash.js/extra', "ui/assets/screen.hash.js\n", 'ui/'.str_repeat('a', 235).'.js'] as $path) {
    check(! StaticUi::isPath($path));
    try {
        StaticUi::assertPath($path);
        throw new RuntimeException('invalid_ui_path_accepted');
    } catch (InvalidArgumentException) {
        check(true);
    }
}
echo json_encode(['result' => 'passed', 'assertions' => $count, 'ui_sha256' => $ui['sha256']], JSON_THROW_ON_ERROR)."\n";
