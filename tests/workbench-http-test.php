<?php

declare(strict_types=1);

use UniScript\Integration\StaticUi;
use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

$directory = sys_get_temp_dir().'/uniscript-workbench-http-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$runtime = new WorkbenchFixtureRuntime($directory.'/builds');
$workbench = new Workbench($directory.'/state', $runtime);
workbenchCopy(dirname(__DIR__).'/examples/reference', $directory.'/source');
mkdir($directory.'/source/ui/assets', 0700, true);
$module = 'export const fixture = "workbench-hashed-module";';
file_put_contents($directory.'/source/ui/assets/screen.abc123.js', $module);
file_put_contents($directory.'/source/ui/assets/theme.123abc.css', 'body { color: navy; }');
$package = $workbench->load($directory.'/source');
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr(strrchr($address, ':'), 1);
$origin = 'http://127.0.0.1:'.$port;
$process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, dirname(__DIR__).'/bin/workbench.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/server.log', 'a'], 2 => ['file', $directory.'/server.log', 'a']], $pipes, dirname(__DIR__), [...getenv(), 'WORKBENCH_STATE' => $directory.'/state', 'WORKBENCH_HOST_ORIGIN' => $origin]);
if (! is_resource($process)) {
    throw new RuntimeException('HTTP test server unavailable');
}
fclose($pipes[0]);

function workbenchHttp(string $origin, string $path, string $method = 'GET', array $headers = [], ?string $body = null): array
{
    $handle = curl_init($origin.$path);
    $responseHeaders = [];
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PATH_AS_IS => true, CURLOPT_PROXY => '', CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return strlen($line);
    }]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    unset($handle);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed: '.$error);
    }

    return ['status' => $status, 'body' => $response, 'headers' => $responseHeaders];
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://'.$address, $error, $errorMessage, 0.05);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    if (! $ready) {
        throw new RuntimeException('HTTP test server did not become ready');
    }
    $state = workbenchHttp($origin, '/api/state');
    workbenchCheck($state['status'] === 200, 'Local state endpoint available');
    $json = json_decode($state['body'], true, 32, JSON_THROW_ON_ERROR);
    $cookie = explode(';', $state['headers']['set-cookie'])[0];
    workbenchCheck(str_contains(strtolower($state['headers']['set-cookie']), 'httponly') && str_contains(strtolower($state['headers']['set-cookie']), 'samesite=strict'), 'Session cookie strict and inaccessible to scripts');
    workbenchCheck(! str_contains($state['body'], 'vault.key') && ! str_contains($state['body'], $directory), 'State response excludes private filesystem paths');
    $headers = ['Cookie: '.$cookie, 'Content-Type: application/json', 'X-CSRF-Token: '.$json['csrf']];
    $response = workbenchHttp($origin, '/api/action', 'POST', ['Cookie: '.$cookie], '{}');
    workbenchCheck($response['status'] === 403, 'POST requires CSRF');
    $response = workbenchHttp($origin, '/api/action', 'POST', [...$headers, 'Origin: https://malicious.example'], '{}');
    workbenchCheck($response['status'] === 403, 'Cross-origin mutation rejected');
    $response = workbenchHttp($origin, '/api/action', 'POST', [...$headers, 'Sec-Fetch-Site: cross-site'], '{}');
    workbenchCheck($response['status'] === 403, 'Cross-site mutation rejected');
    $response = workbenchHttp($origin, '/api/load', 'POST', $headers, '{"source_id":"../../private"}');
    workbenchCheck($response['status'] === 422 && str_contains($response['body'], 'choose_a_configured_workspace'), 'Package load restricted to configured source registry');
    $response = workbenchHttp($origin, '/', 'GET', ['Host: malicious.example']);
    workbenchCheck($response['status'] === 421, 'DNS rebinding Host rejected');
    $response = workbenchHttp($origin, '/');
    workbenchCheck($response['status'] === 200 && str_contains($response['headers']['content-security-policy'], "frame-ancestors 'none'") && str_contains($response['body'], 'POS developer workbench'), 'Host UI uses restrictive frame and script policy');
    $response = workbenchHttp($origin, '/api/bridge/'.WorkbenchState::uuid(), 'POST', $headers, '{}');
    workbenchCheck($response['status'] === 422 && ! str_contains($response['body'], $directory), 'Malformed bridge returns safe JSON error');
    $ui = StaticUi::inspect($directory.'/builds/'.$package['execution_revision'], $package['manifest']);
    $assetPath = '/assets/'.$ui['sha256'].'/'.$package['manifest']['ui']['entrypoint'];
    $packageHost = 'pkg-'.$package['execution_revision'].'.localhost:'.$port;
    $response = workbenchHttp($origin, $assetPath, 'GET', ['Host: '.$packageHost]);
    workbenchCheck($response['status'] === 200 && str_contains($response['headers']['content-security-policy'], "connect-src 'none'") && str_contains($response['headers']['content-security-policy'], 'frame-ancestors '.$origin), 'Package UI uses isolated origin and blocks network');
    workbenchCheck(str_contains($response['headers']['cache-control'], 'immutable') && str_contains($response['headers']['permissions-policy'], 'payment=()'), 'Static package assets immutable and permission restricted');
    $assetBase = '/assets/'.$ui['sha256'].'/ui/';
    $response = workbenchHttp($origin, $assetBase.'assets/screen.abc123.js', 'GET', ['Host: '.$packageHost]);
    workbenchCheck($response['status'] === 200 && $response['body'] === $module && str_contains($response['headers']['content-type'], 'application/javascript'), 'Hashed JavaScript module served from exact asset inventory');
    $response = workbenchHttp($origin, $assetBase.'assets/theme.123abc.css', 'GET', ['Host: '.$packageHost]);
    workbenchCheck($response['status'] === 200 && str_contains($response['headers']['content-type'], 'text/css'), 'Hashed CSS bundle served');
    foreach (['assets/missing.hash.js', 'assets/SCREEN.abc123.js', 'assets/.screen.js', 'assets/screen..hash.js', 'assets./screen.js', '../index.html', '%2e%2e/index.html', 'assets%2fscreen.abc123.js', 'assets/screen.abc123.js/extra', 'assets/screen.abc123.js?nonce=forbidden', 'assets/screen.abc123.JS'] as $unsafe) {
        $response = workbenchHttp($origin, $assetBase.$unsafe, 'GET', ['Host: '.$packageHost]);
        workbenchCheck($response['status'] === 404 && ($response['headers']['x-content-type-options'] ?? '') === 'nosniff' && str_contains($response['headers']['content-security-policy'] ?? '', "default-src 'none'"), 'Unsafe or undeclared bundled asset denied: '.$unsafe);
    }
    $response = workbenchHttp($origin, $assetPath, 'GET', ['Host: '.$packageHost, 'Cookie: '.$cookie]);
    workbenchCheck($response['status'] === 404, 'Isolated package origin rejects host cookies');
    $response = workbenchHttp($origin, '/api/state', 'GET', ['Host: '.$packageHost]);
    workbenchCheck($response['status'] === 404, 'Isolated package origin cannot read host state');
    $response = workbenchHttp($origin, '/pos-webhooks/'.WorkbenchState::uuid(), 'POST', ['Content-Type: application/json'], '{}');
    workbenchCheck($response['status'] === 401, 'Unknown callback endpoint does not persist unauthenticated payload');
    file_put_contents($directory.'/builds/'.$package['execution_revision'].'/ui/assets/screen.abc123.js', 'mutated');
    $response = workbenchHttp($origin, $assetBase.'assets/screen.abc123.js', 'GET', ['Host: '.$packageHost]);
    workbenchCheck($response['status'] === 404 && ($response['headers']['cache-control'] ?? '') === 'no-store', 'Mutated hashed module fails immutable digest check without caching its error');
    echo "Workbench HTTP loopback, CSRF, CSP, asset origin and session isolation checks passed.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    workbenchRemove($directory);
}
