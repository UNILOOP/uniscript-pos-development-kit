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

function removeUiTree(string $root): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}

function fetchUi(string $url, array $options = []): array
{
    $curl = curl_init($url);
    $parts = parse_url($url);
    $options[CURLOPT_RESOLVE] = [$parts['host'].':'.$parts['port'].':127.0.0.1'];
    curl_setopt_array($curl, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 2, CURLOPT_PATH_AS_IS => true]);
    $raw = curl_exec($curl);

    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), is_string($raw) ? $raw : ''];
}

$root = sys_get_temp_dir().'/ui-http-'.bin2hex(random_bytes(6));
mkdir($root.'/empty', 0700, true);
mkdir($root.'/source/ui/assets', 0700, true);
mkdir($root.'/source/ui/release.v1', 0700, true);
$source = dirname(__DIR__).'/examples/custom-ui';
$manifest = Manifest::read($source);
foreach (StaticUi::inspect($source, $manifest)['files'] as $path => $hash) {
    $target = $root.'/source/ui/'.$path;
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    copy($source.'/ui/'.$path, $target);
}
$module = 'export const fixture = "hashed-module";';
$style = 'body { color: navy; }';
file_put_contents($root.'/source/ui/assets/screen.abc123.js', $module);
file_put_contents($root.'/source/ui/assets/theme.123abc.css', $style);
file_put_contents($root.'/source/ui/release.v1/index.hash.html', '<script type="module" src="../assets/screen.abc123.js"></script>');
$ui = StaticUi::inspect($root.'/source', $manifest);
mkdir($root.'/assets', 0700);
foreach ($ui['files'] as $path => $hash) {
    $target = $root.'/assets/'.$path;
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    copy($root.'/source/ui/'.$path, $target);
}
file_put_contents($root.'/assets/assets.json', json_encode(['digest' => $ui['sha256'], 'entrypoint' => 'index.html', 'files' => $ui['files']], JSON_THROW_ON_ERROR));
$socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr($address, strrpos($address, ':') + 1);
$hostname = 'pkg-11111111-1111-4111-8111-111111111111.localhost';
$origin = 'http://'.$hostname.':'.$port;
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $root.'/empty', dirname(__DIR__).'/bin/ui-assets.php'], [0 => ['pipe', 'r'], 1 => ['file', $root.'/server.log', 'a'], 2 => ['file', $root.'/server.log', 'a']], $pipes, null, ['PATH' => getenv('PATH') ?: '', 'PACKAGE_UI_ROOT' => $root.'/assets', 'PACKAGE_UI_ORIGIN' => $origin, 'PACKAGE_UI_HOST_ORIGIN' => 'http://127.0.0.1:8000']);
fclose($pipes[0]);
try {
    $base = $origin.'/assets/'.$ui['sha256'].'/';
    $url = $base.'index.html';
    $response = [0, ''];
    for ($i = 0; $i < 30; $i++) {
        try {
            $response = fetchUi($url);
        } catch (Throwable) {
        }
        if ($response[0] > 0) {
            break;
        }
        usleep(100000);
    }
    check($response[0] === 200);
    check(str_contains($response[1], 'Package local diagnostics'));
    check(str_contains($response[1], "connect-src 'none'"));
    check(str_contains($response[1], "worker-src 'none'"));
    check(str_contains($response[1], 'frame-ancestors http://127.0.0.1:8000'));
    check(str_contains($response[1], 'immutable'));
    check(! str_contains(strtolower($response[1]), 'set-cookie:'));
    $script = fetchUi($base.'assets/screen.abc123.js');
    check($script[0] === 200 && str_ends_with($script[1], $module) && str_contains($script[1], 'application/javascript'));
    $css = fetchUi($base.'assets/theme.123abc.css');
    check($css[0] === 200 && str_ends_with($css[1], $style) && str_contains($css[1], 'text/css'));
    check(fetchUi($base.'release.v1/index.hash.html')[0] === 200);
    check(fetchUi($url, [CURLOPT_HTTPHEADER => ['Host: attacker.invalid']])[0] === 421);
    check(fetchUi($url, [CURLOPT_HTTPHEADER => ['Cookie: historical_host_cookie=synthetic-only']])[0] === 421);
    check(fetchUi($url, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'test'])[0] === 405);
    foreach (['assets.json', 'assets/missing.abc123.js', 'assets/SCREEN.abc123.js', 'assets/.screen.js', 'assets/screen..hash.js', 'assets./screen.js', '../index.html', '%2e%2e/index.html', 'assets%2fscreen.abc123.js', 'assets/screen.abc123.js/extra', 'assets/screen.abc123.js?nonce=forbidden', 'assets/screen.abc123.JS'] as $path) {
        check(fetchUi($base.$path)[0] === 404);
    }
    check(fetchUi($origin.'/private/app.env')[0] === 404);
    check(fetchUi($url.'?nonce=forbidden')[0] === 404);
    file_put_contents($root.'/assets/assets/screen.abc123.js', 'mutated');
    check(fetchUi($base.'assets/screen.abc123.js')[0] === 503);
    file_put_contents($root.'/assets/index.html', 'mutated');
    check(fetchUi($url)[0] === 503);
    echo json_encode(['result' => 'passed', 'assertions' => $count, 'public_asset_url_has_no_session' => true], JSON_THROW_ON_ERROR)."\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    removeUiTree($root);
}
