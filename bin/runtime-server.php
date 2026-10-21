<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($path, ['/health', '/ready'], true)) {
    echo json_encode(['status' => 'ready', 'execution_revision' => getenv('EXECUTION_REVISION')]); return;
}
$token = getenv('INVOCATION_TOKEN') ?: '';
if ($token === '' || !hash_equals('Bearer '.$token, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) { http_response_code(401); echo '{"error":"unauthorized"}'; return; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/invoke') { http_response_code(404); echo '{"error":"not_found"}'; return; }
require dirname(__DIR__).'/bootstrap.php';
$input = file_get_contents('php://input', false, null, 0, 12582913);
if (strlen($input) > 12582912) { http_response_code(413); echo '{"error":"input_too_large"}'; return; }
$decoded = json_decode($input, true);
$limit = UniScript\Integration\PayloadLimits::forPackage($decoded['package_id'] ?? '');
if (strlen($input) > $limit) { http_response_code(413); echo '{"error":"input_too_large"}'; return; }
$deadline = isset($decoded['deadline']) && is_string($decoded['deadline']) ? strtotime($decoded['deadline']) : false;
if ($deadline === false || $deadline <= time()) { http_response_code(422); echo '{"error":"deadline_expired"}'; return; }
$timeout = min(30, $deadline-time());
$environment = ['PACKAGE_ROOT' => '/package', 'EXECUTION_REVISION' => getenv('EXECUTION_REVISION') ?: '', 'HOST_SERVICES_URL' => getenv('HOST_SERVICES_URL') ?: ''];
$process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=0', '/opt/kit/bin/invoke.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, '/tmp', $environment);
fwrite($pipes[0], $input); fclose($pipes[0]);
stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
$output = ''; $started = microtime(true); $failed = false;
do {
    $output .= stream_get_contents($pipes[1], $limit+1-strlen($output));
    stream_get_contents($pipes[2], 65536); // discard untrusted diagnostic output
    $status = proc_get_status($process);
    if (strlen($output) > $limit || microtime(true)-$started >= $timeout) { proc_terminate($process, 9); $failed = true; break; }
    if (!$status['running']) { break; }
    usleep(10000);
} while (true);
$output .= stream_get_contents($pipes[1], max(1,$limit+1-strlen($output)));
fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
try {
    if ($failed || strlen($output) > $limit) { throw new RuntimeException('deadline_or_output_limit'); }
    $result = UniScript\Integration\Result::fromArray(json_decode(trim($output),true,32,JSON_THROW_ON_ERROR),$limit);
} catch (Throwable) {
    // A killed worker may already have created an outbound side effect.
    $result = new UniScript\Integration\Result('unknown_remote_outcome','runtime.worker_lost',[],[],['action'=>'reconcile','resubmit_allowed'=>false]);
}
echo json_encode($result,JSON_THROW_ON_ERROR);
