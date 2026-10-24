<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Archive;
use UniScript\Integration\StaticUi;
use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchState;

$kit = dirname(__DIR__);
$stateDirectory = getenv('WORKBENCH_STATE') ?: $kit.'/var/workbench';
$workspace = realpath(getenv('KIT_WORKSPACE') ?: $kit.'/workspace');
$hostOrigin = getenv('WORKBENCH_HOST_ORIGIN') ?: 'http://127.0.0.1:19100';
if (! preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D', $hostOrigin, $originParts)) {
    http_response_code(503);
    exit('Configure a loopback workbench origin.');
}
$host = $_SERVER['HTTP_HOST'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$workbench = new Workbench($stateDirectory, null, null, getenv('KIT_FIXTURES') ?: null);

function workbenchJson(array $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function workbenchAssetUnavailable(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    exit('Package screen unavailable.');
}

try {
    if (preg_match('/\Apkg-([a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12})\.localhost:'.preg_quote($originParts[1], '/').'\z/D', $host, $packageHost)) {
        if (! in_array($method, ['GET', 'HEAD'], true) || ! empty($_SERVER['HTTP_COOKIE']) || ! preg_match('~\A/assets/([a-f0-9]{64})/(.+)\z~D', $_SERVER['REQUEST_URI'] ?? '', $assetPath) || ! StaticUi::isPath($assetPath[2])) {
            workbenchAssetUnavailable();
        }
        try {
            $asset = $workbench->asset($packageHost[1], $assetPath[1], $assetPath[2]);
        } catch (InvalidArgumentException|RuntimeException) {
            workbenchAssetUnavailable();
        }
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'none'; img-src 'none'; font-src 'none'; frame-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors ".$hostOrigin.'; sandbox allow-scripts allow-same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), clipboard-read=(), clipboard-write=()');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Type: '.$asset['type']);
        if ($method !== 'HEAD') {
            echo $asset['body'];
        }
        exit;
    }
    if ($host !== '127.0.0.1:'.$originParts[1]) {
        http_response_code(421);
        exit('Use the configured loopback workbench URL.');
    }
    if (preg_match('~\A/pos-webhooks/([a-f0-9-]{36})\z~D', (string) $path, $endpoint)) {
        if ($method !== 'POST') {
            workbenchJson(['error' => 'method_not_allowed'], 405);
        }
        $body = file_get_contents('php://input', false, null, 0, 65537);
        $result = $workbench->receive($endpoint[1], $_SERVER['PHP_AUTH_USER'] ?? '', $_SERVER['PHP_AUTH_PW'] ?? '', $body ?: '');
        workbenchJson($result, 202);
    }
    session_name('uniscript_workbench');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-src http://*.localhost:".$originParts[1]."; img-src 'none'; font-src 'none'; worker-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if ($method === 'GET' && in_array($path, ['/workbench.js', '/workbench.css', '/bridge.js'], true)) {
        $file = $path === '/bridge.js' ? $kit.'/ui/bridge.js' : $kit.'/ui/workbench/'.substr($path, 1);
        header('Content-Type: '.(str_ends_with($file, '.css') ? 'text/css; charset=utf-8' : 'application/javascript; charset=utf-8'));
        readfile($file);
        exit;
    }
    if ($method === 'GET' && $path === '/api/state') {
        $choices = [];
        foreach ([$kit.'/examples', $workspace] as $base) {
            if (! is_string($base) || ! is_dir($base)) {
                continue;
            }
            foreach (new DirectoryIterator($base) as $entry) {
                if ($entry->isDot() || ! $entry->isDir() || $entry->isLink() || ! is_file($entry->getPathname().'/integration.json')) {
                    continue;
                }
                $choices[] = ['id' => hash('sha256', (string) realpath($entry->getPathname())), 'label' => basename($base).'/'.$entry->getFilename()];
            }
        }
        workbenchJson([...$workbench->snapshot(), 'csrf' => $_SESSION['csrf'], 'sources' => $choices]);
    }
    if ($method === 'GET' && $path === '/') {
        header('Content-Type: text/html; charset=utf-8');
        readfile($kit.'/ui/workbench/index.html');
        exit;
    }
    if ($method !== 'POST' || ! hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') || (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $hostOrigin) || ! in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin', ['same-origin', 'none'], true)) {
        workbenchJson(['error' => 'workbench_session_denied'], 403);
    }
    if ($path === '/api/upload') {
        $upload = $_FILES['package'] ?? null;
        if (! is_array($upload) || $upload['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($upload['tmp_name']) || $upload['size'] > 20971520) {
            workbenchJson(['error' => 'package_zip_required'], 422);
        }
        $root = $stateDirectory.'/imports/'.WorkbenchState::uuid();
        Archive::unpack($upload['tmp_name'], $root, hash_file('sha256', $upload['tmp_name']));
        workbenchJson($workbench->load($root));
    }
    $maximumBody = $path === '/api/action' ? 1048576 : 131072;
    $body = file_get_contents('php://input', false, null, 0, $maximumBody + 1);
    if (! is_string($body) || strlen($body) > $maximumBody) {
        workbenchJson(['error' => 'workbench_request_limit'], 413);
    }
    $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($input)) {
        workbenchJson(['error' => 'json_object_required'], 422);
    }
    if ($path === '/api/load') {
        foreach ([$kit.'/examples', $workspace] as $base) {
            if (! is_string($base) || ! is_dir($base)) {
                continue;
            }
            foreach (new DirectoryIterator($base) as $entry) {
                if ($entry->isDot() || ! $entry->isDir() || $entry->isLink() || ! is_file($entry->getPathname().'/integration.json')) {
                    continue;
                }
                if (hash_equals(hash('sha256', (string) realpath($entry->getPathname())), (string) ($input['source_id'] ?? ''))) {
                    workbenchJson($workbench->load($entry->getPathname()));
                }
            }
        }
        workbenchJson(['error' => 'choose_a_configured_workspace'], 422);
    }
    if ($path === '/api/action') {
        workbenchJson($workbench->dispatch((string) ($input['action'] ?? ''), $input['arguments'] ?? []));
    }
    if ($path === '/api/callback') {
        workbenchJson($workbench->simulateCallback((string) ($input['order_id'] ?? ''), (string) ($input['status'] ?? '')));
    }
    if ($path === '/api/session') {
        workbenchJson($workbench->openSession((string) ($input['connection_id'] ?? ''), (string) ($input['surface'] ?? ''), session_id(), $hostOrigin, $input['catalog_item_id'] ?? null, $input['order_id'] ?? null));
    }
    if (preg_match('~\A/api/bridge/([a-f0-9-]{36})\z~D', (string) $path, $session)) {
        workbenchJson($workbench->bridge($session[1], $input, session_id(), $hostOrigin));
    }
    workbenchJson(['error' => 'not_found'], 404);
} catch (Throwable $exception) {
    $reason = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException ? $exception->getMessage() : 'workbench_request_failed';
    if (! preg_match('/\A[a-z][a-z0-9_:.-]{1,160}\z/D', $reason)) {
        $reason = 'workbench_request_failed';
    }
    if ($reason === 'webhook_unauthorized') {
        header('WWW-Authenticate: Basic realm="Local POS callback"');
        workbenchJson(['error' => $reason], 401);
    }
    workbenchJson(['error' => $reason], 422);
}
