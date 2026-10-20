<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\MockPos;

$assertions = 0;
$check = static function (bool $condition, string $label) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($label);
    }
};
$directory = sys_get_temp_dir().'/uniscript-mock-pos-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$database = $directory.'/vendor.sqlite';
$mock = new MockPos($database, 'synthetic-user', 'synthetic-password');
$headers = ['Authorization' => 'Basic '.base64_encode('synthetic-user:synthetic-password'), 'Idempotency-Key' => 'synthetic-order', 'X-Location-ID' => 'synthetic-location'];
$body = json_encode(['patient' => 'SYNTHETIC_DO_NOT_PERSIST', 'quantity' => '1.00'], JSON_THROW_ON_ERROR);

try {
    $check($mock->handle('GET', '/health', [], '')['status'] === 200, 'mock health');
    $check($mock->handle('POST', '/order', [], $body)['status'] === 401, 'authorization required');
    $created = $mock->handle('POST', '/order', $headers, $body);
    $reference = $created['body']['data']['orderId'];
    $check($created['status'] === 200 && preg_match('/\A[1-9][0-9]{11}\z/D', $reference) === 1, 'vendor reference');
    $check($mock->handle('POST', '/order', $headers, $body) === $created, 'duplicate stable response');
    $check($mock->handle('POST', '/order', $headers, '{"quantity":"2"}')['status'] === 409, 'same key different body rejected');
    $check($mock->handle('GET', '/metrics', $headers, '')['body']['orders'] === 1, 'one vendor side effect');
    $otherScope = [...$headers, 'X-Location-ID' => 'another-location'];
    $check($mock->handle('GET', '/orders/reconcile', $otherScope, '')['body']['status'] === 'not_found', 'reconciliation does not cross location');
    $check($mock->handle('POST', '/order/'.$reference.'/shipping', $otherScope, '{}')['status'] === 404, 'amendment ownership');
    $amendment = [...$headers, 'Idempotency-Key' => 'synthetic-shipping'];
    $check($mock->handle('POST', '/order/'.$reference.'/shipping', $amendment, '{"tracking":"SYNTHETIC"}')['status'] === 200, 'shipping amendment');
    $check($mock->handle('POST', '/order/'.$reference.'/shipping', $amendment, '{"tracking":"SYNTHETIC"}')['status'] === 200, 'shipping duplicate');
    $check($mock->handle('GET', '/metrics', $headers, '')['body']['amendments'] === 1, 'one amendment effect');
    $lost = [...$headers, 'Idempotency-Key' => 'synthetic-lost'];
    $check($mock->handle('POST', '/orders', $lost, '{"scenario":"accepted_then_lost"}')['status'] === 504, 'acceptance followed by response loss');
    $check($mock->handle('GET', '/orders/reconcile', $lost, '')['body']['status'] === 'accepted', 'durable reconciliation evidence');
    $check($mock->handle('GET', '/metrics', $headers, '')['body']['orders'] === 2, 'one uncertain submission');
    $check(! str_contains(file_get_contents($database), 'SYNTHETIC_DO_NOT_PERSIST'), 'mock does not retain raw patient inputs');

    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $environment = array_merge(getenv(), ['MOCK_POS_USER' => 'synthetic-user', 'MOCK_POS_PASSWORD' => 'synthetic-password', 'MOCK_POS_DATABASE' => $directory.'/http.sqlite']);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, dirname(__DIR__).'/bin/mock-pos.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/server.log', 'a'], 2 => ['file', $directory.'/server.log', 'a']], $pipes, dirname(__DIR__), $environment);

    try {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 0.05);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(10000);
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAuthorization: ".$headers['Authorization']."\r\nIdempotency-Key: real-http-synthetic\r\n", 'content' => '{}', 'timeout' => 5]]);
        $response = json_decode(file_get_contents('http://127.0.0.1:'.$port.'/order', false, $context), true, flags: JSON_THROW_ON_ERROR);
        $check($response['status'] === 'success' && isset($response['data']['orderId']), 'actual HTTP LifeFile protocol');
    } finally {
        proc_terminate($server);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($server);
    }
    echo json_encode(['suite' => 'synthetic-mock-pos', 'assertions' => $assertions, 'passed' => true], JSON_THROW_ON_ERROR)."\n";
} finally {
    unset($mock);
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
