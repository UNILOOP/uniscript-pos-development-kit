<?php

declare(strict_types=1);

use UniScript\Integration\DevelopmentRuntime;
use UniScript\Integration\DevelopmentSandboxTransport;
use UniScript\Integration\WorkbenchState;

define('SANDBOX_FIXTURES_ONLY', true);
require __DIR__.'/development-sandbox-test.php';

$root = sys_get_temp_dir().'/uniscript-sandbox-http-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$router = $root.'/vendor.php';
file_put_contents($router, <<<'PHP'
<?php
$stateFile = getenv('SANDBOX_MOCK_STATE');
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state[$path] = ($state[$path] ?? 0) + 1;
$state['authorization_hash'] = hash('sha256', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$state['account'] = $_SERVER['HTTP_X_ACCOUNT_ID'] ?? null;
$state['idempotency_present'] = isset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
file_put_contents($stateFile, json_encode($state), LOCK_EX);
header('Content-Type: application/json');
if ($path === '/redirect') { header('Location: http://127.0.0.1:1/forbidden', true, 302); echo '{}'; return; }
if ($path === '/large') { echo json_encode(['status' => 'accepted', 'data' => str_repeat('x', 300000)]); return; }
if ($path === '/lost') { echo 'Vendor created test record, but acceptance response was lost'; return; }
if ($path === '/token') {
    parse_str(file_get_contents('php://input'), $body);
    if (($body['grant_type'] ?? null) !== 'client_credentials' || ($body['client_id'] ?? null) !== 'local-client' || ($body['client_secret'] ?? null) !== 'local-client-secret') { http_response_code(401); echo '{}'; return; }
    echo '{"access_token":"local-mock-issued-token","token_type":"Bearer"}'; return;
}
if ($path === '/oauth-orders' && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer local-mock-issued-token') { http_response_code(401); echo '{}'; return; }
if ($path === '/orders' && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic '.base64_encode('local-user:local-password')) { http_response_code(401); echo '{}'; return; }
if ($path === '/generic-orders' && ($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'local-development-only') { http_response_code(401); echo '{}'; return; }
echo '{"status":"accepted","id":"local-vendor-order-1"}';
PHP);
$socket = stream_socket_server('tcp://127.0.0.1:0');
if ($socket === false) {
    throw new RuntimeException('Loopback HTTP test socket unavailable');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, $router], [0 => ['pipe', 'r'], 1 => ['file', $root.'/server.log', 'a'], 2 => ['file', $root.'/server.log', 'a']], $pipes, $root, [...getenv(), 'SANDBOX_MOCK_STATE' => $root.'/requests.json']);
if (! is_resource($process)) {
    throw new RuntimeException('Loopback mock process unavailable');
}
fclose($pipes[0]);
$oldApp = getenv('APP_ENV');
$oldGuard = getenv('POS_KIT_TRANSPORT_LOOPBACK_TEST');
$oldProxy = getenv('https_proxy');
putenv('APP_ENV=testing');
putenv('POS_KIT_TRANSPORT_LOOPBACK_TEST=1');
putenv('https_proxy=http://127.0.0.1:1');
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://'.$address, $error, $message, 0.05);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        } usleep(20000);
    }
    if (! $ready) {
        throw new RuntimeException('Loopback mock did not start');
    }
    $profile = sandboxProfile();
    $profile['base_url'] = 'http://'.$address;
    $options = ['enabled' => true, 'package_id' => 'acme/test-pos', 'profiles' => ['test.vendor' => $profile], 'test_loopback' => true];
    $transport = new DevelopmentSandboxTransport($root.'/live.sqlite', $options);
    $credentials = ['username' => 'local-user', 'password' => 'local-password'];
    $configuration = ['account_id' => 'synthetic-account'];
    $result = $transport->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);
    sandboxCheck($result['status'] === 'accepted', 'Real bounded curl reaches only the explicit loopback test fixture');
    $requests = json_decode(file_get_contents($root.'/requests.json'), true);
    sandboxCheck($requests['authorization_hash'] === hash('sha256', 'Basic '.base64_encode('local-user:local-password')) && $requests['account'] === 'synthetic-account' && $requests['idempotency_present'], 'Real HTTP host-auth, account and logical idempotency headers');
    $transport->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);
    $requests = json_decode(file_get_contents($root.'/requests.json'), true);
    sandboxCheck($requests['/orders'] === 1, 'Duplicate live operation does not issue a second request');
    sandboxCheck($transport->referenceStatus(sandboxEnvelope('orders.status.update'), $result['external_reference_handle'], $configuration)['status'] === 'accepted', 'Accepted reference can be resolved by an authorized original order action');
    sandboxCheck($transport->referenceStatus([...sandboxEnvelope('orders.status.update'), 'connection_id' => 'another-connection'], $result['external_reference_handle'], $configuration)['status'] === 'unknown', 'Reference cannot cross connection scope');
    $lostProfile = $profile;
    $lostProfile['path'] = '/lost';
    $lost = new DevelopmentSandboxTransport($root.'/lost.sqlite', [...$options, 'profiles' => ['test.vendor' => $lostProfile]]);
    sandboxCheck($lost->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__)['status'] === 'unknown', 'Received vendor request with lost/invalid acceptance remains unknown');
    $lost->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);
    $requests = json_decode(file_get_contents($root.'/requests.json'), true);
    sandboxCheck($requests['/lost'] === 1, 'Lost acceptance is never blindly retried over HTTP');
    foreach (['/redirect', '/large'] as $path) {
        $read = sandboxProfile('GET', $path);
        $read['base_url'] = 'http://'.$address;
        $guarded = new DevelopmentSandboxTransport($root.'/'.basename($path).'.sqlite', [...$options, 'profiles' => ['test.vendor' => $read]]);
        if ($path === '/redirect') {
            sandboxCheck($guarded->request(sandboxEnvelope('health.probe'), sandboxArguments('health.probe'), $configuration, $credentials, __DIR__)['status'] === 'rejected', 'Actual redirect rejected without following');
        } else {
            sandboxDenied(fn () => $guarded->request(sandboxEnvelope('health.probe'), sandboxArguments('health.probe'), $configuration, $credentials, __DIR__), 'sandbox_read_or_authentication_failed');
        }
    }
    $oauth = $profile;
    $oauth['path'] = '/oauth-orders';
    $oauth['authentication'] = ['scheme' => 'oauth2_client_credentials', 'client_id_field' => 'client_id', 'client_secret_field' => 'client_secret', 'token_url' => 'http://'.$address.'/token', 'client_authentication' => 'body', 'scopes' => []];
    $oauthTransport = new DevelopmentSandboxTransport($root.'/oauth.sqlite', [...$options, 'profiles' => ['test.vendor' => $oauth]]);
    sandboxCheck($oauthTransport->request(sandboxEnvelope(), sandboxArguments(), $configuration, ['client_id' => 'local-client', 'client_secret' => 'local-client-secret'], __DIR__)['status'] === 'accepted', 'Real local OAuth client-credential exchange stays host owned');
    $requests = json_decode(file_get_contents($root.'/requests.json'), true);
    sandboxCheck(($requests['/token'] ?? 0) === 1 && ($requests['/oauth-orders'] ?? 0) === 1, 'Exactly one token and one vendor order request');
    if (in_array('--docker', $argv, true)) {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, 32, JSON_THROW_ON_ERROR);
        $health = sandboxProfile('GET', '/health');
        $submitProfile = sandboxProfile('POST', '/generic-orders');
        foreach (['health', 'submitProfile'] as $name) {
            ${$name}['base_url'] = 'http://'.$address;
            ${$name}['package_ids'] = [$fixture['package_id']];
            ${$name}['authentication'] = ['scheme' => 'header_secret', 'header' => 'X-Api-Key', 'secret_field' => 'api_key'];
        }
        $submitProfile['body_schema'] = ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100], 'scenario' => ['type' => 'string', 'maxLength' => 100]], 'required' => ['order_id', 'scenario'], 'additionalProperties' => false];
        unset($submitProfile['body_account_bindings']);
        $live = new DevelopmentSandboxTransport($root.'/docker-live.sqlite', ['enabled' => true, 'package_id' => $fixture['package_id'], 'profiles' => ['synthetic.health' => $health, 'synthetic.orders' => $submitProfile], 'test_loopback' => true]);
        $runtime = new DevelopmentRuntime($root.'/runtime', ['sandbox_transport' => $live]);
        $deployment = $runtime->prepare(dirname(__DIR__).'/examples/reference', WorkbenchState::uuid());
        $scope = ['connection_id' => 'docker-live-sandbox-connection', 'idempotency_key' => 'docker-live-sandbox-order'];
        $healthResult = $runtime->invoke($deployment, 'health.probe', [], $fixture['configuration'], $fixture['credentials'], [], $scope);
        sandboxCheck($healthResult['kind'] === 'success' && $runtime->lastEvidence()['network_access'] === true && $runtime->lastEvidence()['simulated'] === false, 'Networkless Docker package uses host-owned HTTP adapter for actual loopback probe');
        $orderResult = $runtime->invoke($deployment, 'orders.submit', $fixture['order'], $fixture['configuration'], $fixture['credentials'], [], $scope);
        sandboxCheck($orderResult['kind'] === 'remote_acceptance' && is_string($orderResult['data']['external_reference_handle']), 'Real isolated package receives managed acceptance handle from HTTP fixture');
        $runtime->invoke($deployment, 'orders.submit', $fixture['order'], $fixture['configuration'], $fixture['credentials'], [], $scope);
        $requests = json_decode(file_get_contents($root.'/requests.json'), true);
        sandboxCheck(($requests['/generic-orders'] ?? 0) === 1 && $runtime->lastEvidence()['network_access'] === false, 'Duplicate isolated runtime invocation reuses durable live HTTP evidence');
    }
    echo 'Live Sandbox adapter loopback HTTP integration: '.$sandboxChecks." checks passed; no external vendor called.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    $oldApp === false ? putenv('APP_ENV') : putenv('APP_ENV='.$oldApp);
    $oldGuard === false ? putenv('POS_KIT_TRANSPORT_LOOPBACK_TEST') : putenv('POS_KIT_TRANSPORT_LOOPBACK_TEST='.$oldGuard);
    $oldProxy === false ? putenv('https_proxy') : putenv('https_proxy='.$oldProxy);
    sandboxRemove($root);
}
