<?php

declare(strict_types=1);

define('SANDBOX_FIXTURES_ONLY', true);
require __DIR__.'/development-sandbox-test.php';

use UniScript\Integration\DevelopmentConnectionEvidence;
use UniScript\Integration\DevelopmentSandboxTransport;

$root = sys_get_temp_dir().'/pos-connection-proof-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$profile = sandboxProfile('GET', '/identity');
$profile['operations'] = ['connection.test', 'health.probe'];
$descriptor = ['profile_version' => 1, 'enabled' => true, 'package_ids' => ['acme/test-pos'], 'transport_profile' => 'test.identity',
    'principal_path' => 'user.id', 'success_path' => 'authenticated', 'success_value' => true,
    'account_response_bindings' => ['account' => 'account'], 'synthetic_only' => false, 'challenge_path' => 'challenge'];
$options = ['enabled' => true, 'package_id' => 'acme/test-pos', 'profiles' => ['test.identity' => $profile],
    'verification_profile' => 'test.account', 'verification_profiles' => ['test.account' => $descriptor]];
$deployment = ['manifest' => ['package_id' => 'acme/test-pos'], 'execution_revision' => 'proof-execution-123', 'package_root' => dirname(__DIR__).'/examples/reference'];
$config = ['account_id' => 'synthetic-account'];
$credentials = ['username' => 'private-fixture-user', 'password' => 'private-fixture-secret'];
$sent = 0;
$good = static function (array $request) use (&$sent): array {
    $sent++;

    return ['status' => 200, 'body' => json_encode(['authenticated' => true, 'user' => ['id' => 'private-fixture-user'], 'account' => 'synthetic-account', 'challenge' => $request['headers']['X-UniScript-Challenge'] ?? null])];
};
try {
    $transport = new DevelopmentSandboxTransport($root.'/good.sqlite', $options, static fn (): array => ['8.8.8.8'], $good);
    $proof = (new DevelopmentConnectionEvidence($transport))->verify($deployment, $config, $credentials);
    sandboxCheck($proof['verified'] === true && $proof['synthetic'] === false && $sent === 1, 'Trusted HTTP identity independently verifies credentials and account');
    sandboxCheck(strlen($proof['profile_sha256']) === 64 && $proof['principal_fingerprint'] === hash('sha256', 'private-fixture-user'), 'Stable identity fingerprints survive package redaction');
    sandboxCheck(! str_contains(json_encode($proof), 'private-fixture') && ! str_contains(json_encode($proof), 'synthetic-account'), 'Proof exposes no raw principal, credentials or account');
    $badCases = [
        'wrong account' => [['authenticated' => true, 'user' => ['id' => 'user-7'], 'account' => 'other-account'], 'sandbox_verification_account_mismatch'],
        'invalid principal' => [['authenticated' => true, 'user' => ['id' => 'bad principal'], 'account' => 'synthetic-account'], 'sandbox_verification_principal_invalid'],
        'authentication unproven' => [['authenticated' => false, 'user' => ['id' => 'user-7'], 'account' => 'synthetic-account'], 'sandbox_verification_authentication_unproven'],
    ];
    foreach ($badCases as $name => [$body, $reason]) {
        $sender = static fn (array $request): array => ['status' => 200, 'body' => json_encode([...$body, 'challenge' => $request['headers']['X-UniScript-Challenge']])];
        $instance = new DevelopmentSandboxTransport($root.'/'.md5($name).'.sqlite', $options, static fn (): array => ['8.8.8.8'], $sender);
        sandboxDenied(fn () => (new DevelopmentConnectionEvidence($instance))->verify($deployment, $config, $credentials), $reason);
    }
    $wrongChallenge = new DevelopmentSandboxTransport($root.'/challenge.sqlite', $options, static fn (): array => ['8.8.8.8'], static fn (): array => ['status' => 200, 'body' => '{"authenticated":true,"user":{"id":"user-7"},"account":"synthetic-account","challenge":"stale"}']);
    sandboxDenied(fn () => (new DevelopmentConnectionEvidence($wrongChallenge))->verify($deployment, $config, $credentials), 'sandbox_verification_challenge_mismatch');
    $missing = new DevelopmentSandboxTransport($root.'/missing.sqlite', ['enabled' => true, 'package_id' => 'acme/test-pos', 'profiles' => ['test.identity' => $profile]], static fn (): array => ['8.8.8.8'], $good);
    sandboxDenied(fn () => (new DevelopmentConnectionEvidence($missing))->verify($deployment, $config, $credentials), 'sandbox_verification_descriptor_required');
    $unproven = $descriptor;
    $unproven['account_response_bindings'] = [];
    $missingScope = new DevelopmentSandboxTransport($root.'/missing-scope.sqlite', [...$options, 'verification_profiles' => ['test.account' => $unproven]], static fn (): array => ['8.8.8.8'], $good);
    sandboxDenied(fn () => (new DevelopmentConnectionEvidence($missingScope))->verify($deployment, $config, $credentials), 'sandbox_verification_account_proof_incomplete');
    sandboxDenied(fn () => (new DevelopmentConnectionEvidence($transport))->verify($deployment, $config, $credentials, ['environment' => 'production']), 'sandbox_verification_environment_denied');
    echo 'Trusted connection identity proof: '.$sandboxChecks." checks passed.\n";
} finally {
    sandboxRemove($root);
}
