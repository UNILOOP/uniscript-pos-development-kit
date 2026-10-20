<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Invocation;
use UniScript\Integration\Result;
use UniScript\Integration\Runtime;
use UniScript\Integration\ScopedServices;

// One process per invocation prevents mutable package globals crossing invocations.
// OS/container isolation is mandatory outside synthetic development.
try {
    $input = fgets(STDIN, 1048578);
    if ($input === false) { throw new RuntimeException('missing_invocation'); }
    if (strlen($input) > 1048576) { throw new RuntimeException('input_too_large'); }
    $invocation = Invocation::fromArray(json_decode($input, true, 32, JSON_THROW_ON_ERROR));
    $root = getenv('PACKAGE_ROOT') ?: '/package';
    $revision = getenv('EXECUTION_REVISION') ?: throw new RuntimeException('execution_revision_not_configured');
    $endpoint = getenv('HOST_SERVICES_URL') ?: '';
    $services = new ScopedServices($invocation, static function (array $request) use ($endpoint): array {
        if (getenv('SERVICE_TRANSPORT') === 'stdio') {
            // The trusted supervisor validates every frame and grant; stdout is untrusted.
            $channel = STDOUT;
            fwrite($channel, json_encode(['service_request' => $request], JSON_THROW_ON_ERROR)."\n");
            fflush($channel);
            $line = fgets(STDIN, 1048578);
            if ($line === false) { throw new RuntimeException('service_channel_closed'); }
            $reply = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (isset($reply['error'])) { throw new RuntimeException('service_denied'); }
            return $reply['response'];
        }
        if ($endpoint === '') { throw new RuntimeException('host_services_unavailable'); }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($request, JSON_THROW_ON_ERROR), 'timeout' => 5, 'follow_location' => 0, 'max_redirects' => 0], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $response = file_get_contents($endpoint, false, $context, 0, 1048577);
        if ($response === false || strlen($response) > 1048576) { throw new RuntimeException('service_transport_failed'); }
        return json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    });
    ob_start();
    try { $result = (new Runtime($root, $revision))->invoke($invocation, $services); }
    finally { ob_end_clean(); }
} catch (Throwable) {
    // Never echo exception details or package output: they can contain sensitive input.
    $result = new Result('permanent_failure', 'runtime.invocation_failed');
}
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
