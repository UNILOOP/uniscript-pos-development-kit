<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\DevelopmentRuntime;
use UniScript\Integration\DevelopmentSandboxTransport;

$sandboxChecks = 0;
function sandboxCheck(bool $condition, string $message): void
{
    global $sandboxChecks;
    $sandboxChecks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function sandboxDenied(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (InvalidArgumentException|RuntimeException $error) {
        sandboxCheck($error->getMessage() === $expected, 'Expected '.$expected.'; received '.$error->getMessage());

        return;
    }
    throw new RuntimeException('Expected denial: '.$expected);
}
function sandboxEnvelope(string $operation = 'orders.submit', string $key = 'test-logical-order'): array
{
    return ['protocol_version' => '1', 'invocation_id' => 'sandbox-test-invocation', 'command_id' => 'sandbox-test-command', 'attempt_id' => 'sandbox-test-attempt', 'correlation_id' => 'sandbox-test-correlation', 'package_id' => 'acme/test-pos', 'execution_revision' => 'sandbox-test-execution', 'connection_id' => 'sandbox-test-connection', 'organization_id' => 'sandbox-test-organization', 'location_id' => 'sandbox-test-location', 'environment' => 'sandbox', 'deployment_environment' => 'local', 'operation' => $operation, 'schema_version' => '1', 'routing_revision' => '1', 'configuration_revision' => '1', 'mapping_revision' => '1', 'credential_revision' => '1', 'idempotency_key' => $key, 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 30), 'actor_id' => 'sandbox-test-actor', 'request_budget' => 10, 'input' => [], 'service_grant' => str_repeat('a', 64)];
}
function sandboxProfile(string $method = 'POST', string $path = '/orders'): array
{
    $profile = ['profile_version' => 1, 'enabled' => true, 'package_ids' => ['acme/test-pos'], 'operations' => ['orders.submit', 'orders.reconcile', 'orders.status.update', 'health.probe', 'connection.test'], 'method' => $method, 'base_url' => 'https://vendor.example', 'path' => $path, 'account_key' => 'test-vendor', 'account_fields' => ['account' => 'account_id'], 'config_headers' => ['X-Account-ID' => 'account_id'], 'authentication' => ['scheme' => 'basic', 'username_field' => 'username', 'password_field' => 'password'], 'success_path' => 'status', 'success_value' => 'accepted'];
    if ($method === 'GET') {
        $profile['success_policy'] = 'read_only_json';
    } else {
        $profile['reference_path'] = 'id';
        $profile['body_schema'] = ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100], 'account_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]], 'required' => ['order_id', 'account_id'], 'additionalProperties' => false];
        $profile['body_account_bindings'] = ['account_id' => 'account_id'];
    }

    return $profile;
}
function sandboxArguments(string $operation = 'orders.submit', string $key = 'test-logical-order'): array
{
    return ['profile' => 'test.vendor', 'operation_key' => $operation, 'idempotency_key' => $key, 'parameters' => [], ...($operation === 'health.probe' ? [] : ['body' => ['order_id' => 'synthetic-order', 'account_id' => 'synthetic-account']])];
}
function sandboxRemove(string $directory): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

if (defined('SANDBOX_FIXTURES_ONLY') && SANDBOX_FIXTURES_ONLY) {
    return;
}

$root = sys_get_temp_dir().'/uniscript-sandbox-transport-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$configuration = ['account_id' => 'synthetic-account'];
$credentials = ['username' => 'private-fixture-user', 'password' => 'private-fixture-password'];
$options = ['enabled' => true, 'package_id' => 'acme/test-pos', 'profiles' => ['test.vendor' => sandboxProfile()]];
$calls = [];
$sender = static function (array $request) use (&$calls): array {
    $calls[] = $request;

    return ['status' => 200, 'body' => '{"status":"accepted","id":"private-vendor-reference","password":"private-fixture-password","echo":"private-fixture-user"}'];
};
$resolver = static fn (string $host): array => ['8.8.8.8'];
try {
    putenv('POS_KIT_LIVE_SANDBOX=0');
    sandboxCheck(DevelopmentSandboxTransport::fromEnvironment($root.'/disabled.sqlite') === null, 'External egress disabled by default');
    sandboxDenied(fn () => new DevelopmentSandboxTransport($root.'/missing.sqlite', ['enabled' => false]), 'sandbox_transport_explicit_configuration_required');
    sandboxDenied(fn () => new DevelopmentSandboxTransport($root.'/loopback.sqlite', [...$options, 'test_loopback' => true]), 'sandbox_loopback_test_guard');
    $transport = new DevelopmentSandboxTransport($root.'/ledger.sqlite', $options, $resolver, $sender);
    $result = $transport->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, dirname(__DIR__).'/examples/reference');
    sandboxCheck($result['status'] === 'accepted' && is_string($result['external_reference_handle']), 'Accepted response has an opaque broker-owned reference');
    sandboxCheck($calls[0]['endpoint']['address'] === '8.8.8.8' && $calls[0]['headers']['X-Account-ID'] === 'synthetic-account', 'Trusted DNS pin and account header used');
    sandboxCheck($calls[0]['headers']['Authorization'] === 'Basic '.base64_encode('private-fixture-user:private-fixture-password'), 'Stored credentials injected only by host');
    sandboxCheck(! str_contains(json_encode($result), 'private-fixture-'), 'Vendor response cannot reflect credential values');
    sandboxCheck(! array_key_exists('_reference', $result), 'No internal reference field exposed to package');
    sandboxCheck($transport->evidence()['external_references'][0]['reference'] === 'private-vendor-reference', 'Raw reference available only as trusted host evidence');
    $again = $transport->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, dirname(__DIR__).'/examples/reference');
    sandboxCheck($again === $result && count($calls) === 1 && ! $transport->evidence()['network_access'], 'Exact duplicate reuses encrypted outcome without another HTTP request');
    $altered = sandboxArguments();
    $altered['body']['order_id'] = 'different-order';
    sandboxDenied(fn () => $transport->request(sandboxEnvelope(), $altered, $configuration, $credentials, __DIR__), 'sandbox_idempotency_conflict');
    $altered = sandboxArguments();
    $altered['body']['account_id'] = 'another-account';
    sandboxDenied(fn () => $transport->request(sandboxEnvelope(), $altered, $configuration, $credentials, __DIR__), 'sandbox_body_account_mismatch');
    sandboxDenied(fn () => $transport->request([...sandboxEnvelope(), 'package_id' => 'other/test-pos'], sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_package_or_environment_denied');
    sandboxDenied(fn () => $transport->request([...sandboxEnvelope(), 'environment' => 'production'], sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_package_or_environment_denied');
    sandboxDenied(fn () => $transport->request(sandboxEnvelope(), [...sandboxArguments(), 'url' => 'https://other.example'], $configuration, $credentials, __DIR__), 'sandbox_invocation_binding_denied');
    $wire = sandboxProfile();
    $wire['body_schema'] = ['type' => 'object', 'properties' => ['orderId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]], 'required' => ['orderId'], 'additionalProperties' => false];
    unset($wire['body_account_bindings']);
    $wireTransport = new DevelopmentSandboxTransport($root.'/wire.sqlite', [...$options, 'profiles' => ['test.vendor' => $wire]], $resolver, $sender);
    $wireResult = $wireTransport->request(sandboxEnvelope(), [...sandboxArguments(), 'body' => ['orderId' => 'synthetic-wire-order']], $configuration, $credentials, __DIR__);
    sandboxCheck($wireResult['status'] === 'accepted', 'Portable bounded wire schema supports vendor CamelCase without loosening ordinary package resource schemas');
    foreach (['127.0.0.1', '169.254.169.254', '10.1.2.3', '100.100.100.200', '192.168.1.1', '192.0.2.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', 'fc00::1', '2001:db8::1'] as $ip) {
        $blocked = new DevelopmentSandboxTransport($root.'/blocked-'.md5($ip).'.sqlite', $options, static fn (): array => [$ip], $sender);
        sandboxDenied(fn () => $blocked->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_private_destination_denied');
    }
    $mixed = new DevelopmentSandboxTransport($root.'/mixed.sqlite', $options, static fn (): array => ['8.8.8.8', '127.0.0.1'], $sender);
    sandboxDenied(fn () => $mixed->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_private_destination_denied');
    $unsupported = sandboxProfile();
    $unsupported['authentication'] = ['scheme' => 'custom_script'];
    $invalidAuth = new DevelopmentSandboxTransport($root.'/unsupported-auth.sqlite', [...$options, 'profiles' => ['test.vendor' => $unsupported]], $resolver, $sender);
    sandboxDenied(fn () => $invalidAuth->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_authentication_unsupported');
    $collision = sandboxProfile();
    $collision['config_headers']['x-api-key'] = 'account_id';
    $collision['authentication'] = ['scheme' => 'header_secret', 'header' => 'X-Api-Key', 'secret_field' => 'api_key'];
    $invalidHeader = new DevelopmentSandboxTransport($root.'/header-collision.sqlite', [...$options, 'profiles' => ['test.vendor' => $collision]], $resolver, $sender);
    sandboxDenied(fn () => $invalidHeader->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_secret_header_invalid');
    $badProfile = sandboxProfile();
    $badProfile['base_url'] = 'http://vendor.example';
    $bad = new DevelopmentSandboxTransport($root.'/http.sqlite', [...$options, 'profiles' => ['test.vendor' => $badProfile]], $resolver, $sender);
    sandboxDenied(fn () => $bad->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_https_fixed_url_required');
    foreach (['2130706433', '0177.0.0.1', '0x7f.1', '127.1'] as $host) {
        $ambiguous = sandboxProfile();
        $ambiguous['base_url'] = 'https://'.$host;
        $guarded = new DevelopmentSandboxTransport($root.'/numeric-'.md5($host).'.sqlite', [...$options, 'profiles' => ['test.vendor' => $ambiguous]], $resolver, $sender);
        sandboxDenied(fn () => $guarded->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__), 'sandbox_ambiguous_numeric_host_denied');
    }
    $lostCalls = 0;
    $lost = new DevelopmentSandboxTransport($root.'/lost.sqlite', $options, $resolver, static function () use (&$lostCalls): array {
        $lostCalls++;
        throw new RuntimeException('Vendor accepted but connection dropped');
    });
    $unknown = $lost->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);
    sandboxCheck($unknown['status'] === 'unknown', 'Lost response retained as unknown');
    $lost->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);
    sandboxCheck($lostCalls === 1, 'Unknown outcome never automatically resubmitted');
    $concurrentCalls = 0;
    $other = new DevelopmentSandboxTransport($root.'/concurrent.sqlite', $options, $resolver, static function () use (&$concurrentCalls): array {
        $concurrentCalls++;

        return ['status' => 200, 'body' => '{}'];
    });
    $concurrentResult = null;
    $first = new DevelopmentSandboxTransport($root.'/concurrent.sqlite', $options, $resolver, static function () use ($other, $configuration, $credentials, &$concurrentResult, &$concurrentCalls): array {
        $concurrentCalls++;
        $concurrentResult = $other->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);

        return ['status' => 200, 'body' => '{"status":"accepted","id":"concurrent-reference"}'];
    });
    $first->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);
    sandboxCheck($concurrentCalls === 1 && $concurrentResult['status'] === 'unknown', 'Concurrent claim cannot dispatch a second mutation while first is in flight');
    $reopened = new DevelopmentSandboxTransport($root.'/ledger.sqlite', $options, $resolver, $sender);
    $callsBeforeReopen = count($calls);
    sandboxCheck($reopened->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__) === $result && count($calls) === $callsBeforeReopen, 'Durable exactly-once outcome survives process restart');
    $ledger = file_get_contents($root.'/ledger.sqlite');
    sandboxCheck(! str_contains($ledger, 'private-vendor-reference') && ! str_contains($ledger, 'private-fixture-password'), 'Ledger contains sealed responses and references');
    $ownership = new DevelopmentSandboxTransport($root.'/ownership.sqlite', $options, $resolver, $sender);
    $original = $ownership->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__);
    $duplicateReference = $ownership->request(sandboxEnvelope(key: 'different-order'), sandboxArguments(key: 'different-order'), $configuration, $credentials, __DIR__);
    sandboxCheck($duplicateReference === ['status' => 'unknown', 'reason_code' => 'managed_reference_ownership_conflict'], 'A new order cannot reuse another immutable order reference');
    sandboxCheck($ownership->referenceStatus(sandboxEnvelope('orders.status.update'), $original['external_reference_handle'], $configuration)['status'] === 'unknown', 'Ambiguous reference no longer authorizes scoped order actions');
    sandboxCheck($ownership->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__)['status'] === 'unknown', 'Previously cached reference also quarantined after ownership conflict');
    $rejectedWrite = new DevelopmentSandboxTransport($root.'/rejected-write.sqlite', $options, $resolver, static fn (): array => ['status' => 422, 'body' => '{"error":"invalid"}']);
    sandboxCheck($rejectedWrite->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, __DIR__) === ['status' => 'unknown', 'reason_code' => 'managed_acceptance_unknown'], 'Claimed vendor rejection matches host unknown-outcome contract');
    sandboxCheck(array_keys($result) === ['status', 'external_reference_handle'], 'Managed write response exposes only opaque acceptance evidence');
    $big = sandboxProfile();
    $big['body_schema'] = ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'maxItems' => 1000, 'items' => ['type' => 'string', 'maxLength' => 4096]]], 'required' => ['rows'], 'additionalProperties' => false];
    unset($big['body_account_bindings']);
    $budgeted = new DevelopmentSandboxTransport($root.'/budget.sqlite', [...$options, 'profiles' => ['test.vendor' => $big]], $resolver, $sender);
    $beforeOversize = count($calls);
    sandboxDenied(fn () => $budgeted->request(sandboxEnvelope(), [...sandboxArguments(), 'body' => ['rows' => array_fill(0, 200, str_repeat('x', 1000))]], $configuration, $credentials, __DIR__), 'sandbox_request_limit');
    sandboxCheck(count($calls) === $beforeOversize, 'Arguments above host 128 KiB service budget cause no token or vendor request');
    foreach ([['scheme' => 'bearer', 'token_field' => 'api_key'], ['scheme' => 'header_secret', 'header' => 'X-Api-Key', 'secret_field' => 'api_key']] as $index => $auth) {
        $profile = sandboxProfile();
        $profile['authentication'] = $auth;
        $test = new DevelopmentSandboxTransport($root.'/auth-'.$index.'.sqlite', [...$options, 'profiles' => ['test.vendor' => $profile]], $resolver, $sender);
        $test->request(sandboxEnvelope(), sandboxArguments(), $configuration, ['api_key' => 'private-header-token'], __DIR__);
        $headers = $calls[array_key_last($calls)]['headers'];
        sandboxCheck(($headers[$auth['scheme'] === 'bearer' ? 'Authorization' : 'X-Api-Key'] ?? null) === ($auth['scheme'] === 'bearer' ? 'Bearer ' : '').'private-header-token', 'Reviewed auth scheme '.$auth['scheme']);
    }
    $oauth = sandboxProfile();
    $oauth['authentication'] = ['scheme' => 'oauth2_client_credentials', 'client_id_field' => 'client_id', 'client_secret_field' => 'client_secret', 'token_url' => 'https://auth.vendor.example/oauth/token', 'client_authentication' => 'body', 'scopes' => ['orders.write']];
    $oauthRequests = [];
    $oauthTransport = new DevelopmentSandboxTransport($root.'/oauth.sqlite', [...$options, 'profiles' => ['test.vendor' => $oauth]], $resolver, static function (array $request) use (&$oauthRequests): array {
        $oauthRequests[] = $request;

        return ['status' => 200, 'body' => str_contains($request['endpoint']['url'], '/oauth/token') ? '{"access_token":"private-issued-token","token_type":"Bearer"}' : '{"status":"accepted","id":"vendor-oauth-order","echo":"private-issued-token"}'];
    });
    $oauthResult = $oauthTransport->request(sandboxEnvelope(), sandboxArguments(), $configuration, ['client_id' => 'private-client', 'client_secret' => 'private-client-secret'], __DIR__);
    sandboxCheck(count($oauthRequests) === 2 && $oauthRequests[1]['headers']['Authorization'] === 'Bearer private-issued-token', 'OAuth obtains bounded token then authenticates vendor request');
    sandboxCheck(str_contains($oauthRequests[0]['body'], 'grant_type=client_credentials') && str_contains($oauthRequests[0]['body'], 'scope=orders.write'), 'OAuth request uses fixed client-credential grant and reviewed scopes');
    sandboxCheck(! str_contains(json_encode($oauthResult), 'private-issued-token'), 'Issued OAuth token never returned to package');
    $readProfile = sandboxProfile('GET', '/identity');
    $readTransport = new DevelopmentSandboxTransport($root.'/redaction.sqlite', [...$options, 'profiles' => ['test.vendor' => $readProfile]], $resolver, $sender);
    $readResult = $readTransport->request(sandboxEnvelope('health.probe'), sandboxArguments('health.probe'), $configuration, $credentials, __DIR__);
    sandboxCheck(isset($readResult['data']) && ! str_contains(json_encode($readResult), 'private-fixture-'), 'Read-only vendor JSON also redacts credential-looking and reflected secret values');
    mkdir($root.'/package', 0700);
    $profileFile = $root.'/package/profiles.json';
    file_put_contents($profileFile, json_encode(['version' => 1, 'environment' => 'sandbox', 'profiles' => $options['profiles']], JSON_THROW_ON_ERROR));
    putenv('POS_KIT_LIVE_SANDBOX=1');
    putenv('POS_KIT_LIVE_SANDBOX_PACKAGE=acme/test-pos');
    putenv('POS_KIT_SANDBOX_PROFILES='.$profileFile);
    $fromEnvironment = DevelopmentSandboxTransport::fromEnvironment($root.'/from-environment.sqlite');
    sandboxDenied(fn () => $fromEnvironment->request(sandboxEnvelope(), sandboxArguments(), $configuration, $credentials, $root.'/package'), 'sandbox_profiles_must_be_host_owned');
    $source = dirname(__DIR__).'/examples/reference';
    $copy = $root.'/source-package';
    mkdir($copy, 0700);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $target = $copy.'/'.substr($file->getPathname(), strlen($source) + 1);
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
    copy($profileFile, $copy.'/profiles.json');
    putenv('POS_KIT_LIVE_SANDBOX_PACKAGE=uniscript/reference-pos');
    putenv('POS_KIT_SANDBOX_PROFILES='.$copy.'/profiles.json');
    $guardedRuntime = new DevelopmentRuntime($root.'/source-guard');
    sandboxDenied(fn () => $guardedRuntime->prepare($copy, 'source-profile-guard'), 'sandbox_profiles_must_be_host_owned');
    putenv('POS_KIT_LIVE_SANDBOX=0');
    putenv('POS_KIT_LIVE_SANDBOX_PACKAGE');
    putenv('POS_KIT_SANDBOX_PROFILES');
    $read = sandboxProfile('GET', '/identity');
    $redirect = new DevelopmentSandboxTransport($root.'/redirect.sqlite', [...$options, 'profiles' => ['test.vendor' => $read]], $resolver, static fn (): array => ['status' => 302, 'body' => '{}']);
    sandboxCheck($redirect->request(sandboxEnvelope('health.probe'), sandboxArguments('health.probe'), $configuration, $credentials, __DIR__) === ['status' => 'rejected', 'reason_code' => 'managed_read_rejected'], 'Read-only redirect rejected without following');
    echo 'Live Sandbox transport policy, authentication, secret isolation and durable outcomes: '.$sandboxChecks." checks passed.\n";
} finally {
    sandboxRemove($root);
}
