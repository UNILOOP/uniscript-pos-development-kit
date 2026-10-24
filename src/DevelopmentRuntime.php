<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use RuntimeException;

/** Trusted local adapter. Development builds are never represented as independently certified releases. */
final class DevelopmentRuntime
{
    private array $evidence = [];

    public function __construct(private readonly string $state, private readonly array $options = [])
    {
        if (! is_dir($state) && ! mkdir($state, 0700, true)) {
            throw new RuntimeException('development_state_unavailable');
        }
    }

    public function prepare(string $packageRoot, string $executionId): array
    {
        self::execution($executionId);
        $directory = $this->state.'/executions/'.$executionId;
        $root = realpath($packageRoot);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('package_directory_required');
        }
        $manifest = Manifest::read($root);
        $sandbox = $this->options['sandbox_transport'] ?? null;
        if (! $sandbox instanceof DevelopmentSandboxTransport && getenv('POS_KIT_LIVE_SANDBOX') === '1' && getenv('POS_KIT_LIVE_SANDBOX_PACKAGE') === $manifest['package_id']) {
            $sandbox = DevelopmentSandboxTransport::fromEnvironment($this->state.'/sandbox-transports.sqlite');
        }
        if ($sandbox instanceof DevelopmentSandboxTransport && $sandbox->packageId() === $manifest['package_id']) {
            $sandbox->assertHostOwned($root);
        }
        if (is_file($directory.'/deployment.json')) {
            $stored = JsonDocument::decode(file_get_contents($directory.'/deployment.json'), true);
            if ($sandbox instanceof DevelopmentSandboxTransport && $sandbox->packageId() === $stored['manifest']['package_id']) {
                $sandbox->assertHostOwned($stored['source_root'] ?? throw new RuntimeException('sandbox_execution_source_binding_missing'));
            }

            return $stored;
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('development_execution_unavailable');
        }
        $archive = $directory.'/source.zip';
        Process::run([PHP_BINARY, dirname(__DIR__).'/bin/package.php', $root, $archive], 30);
        $hash = hash_file('sha256', $archive);
        Archive::unpack($archive, $directory.'/package', $hash);
        $platform = new Platform($this->state.'/platform');
        $build = $platform->enqueue('builds', ['execution_uuid' => $executionId, 'source_sha256' => $hash, 'package_id' => $manifest['package_id'], 'package_version' => $manifest['version']], $archive);
        $platform->work();
        $build = $platform->read('builds', $build['id']);
        if ($build['status'] !== 'succeeded') {
            throw new RuntimeException('development_build_failed:'.($build['_diagnostic'] ?? $build['reason_code'] ?? 'unknown'));
        }
        $deployment = [
            'status' => 'development_ready', 'certified' => false, 'execution_revision' => $executionId,
            'manifest' => $manifest, 'package_root' => $directory.'/package', 'source_root' => $root, 'archive' => $archive,
            'source_sha256' => $hash, 'image_sha256' => $build['digests']['executable_image_sha256'], 'digests' => $build['digests'], 'build_id' => $build['id'],
        ];
        self::save($directory.'/deployment.json', $deployment);

        return $deployment;
    }

    public function invoke(array $deployment, string $operation, array $input, array $configuration = [], array $credentials = [], array $resources = [], array $scope = []): array
    {
        $executionId = $deployment['execution_revision'] ?? '';
        self::execution($executionId);
        $record = $this->state.'/executions/'.$executionId.'/deployment.json';
        if (! is_file($record)) {
            throw new RuntimeException('development_execution_not_prepared');
        }
        $stored = JsonDocument::decode(file_get_contents($record), true);
        if (($deployment['source_sha256'] ?? null) !== $stored['source_sha256'] || ($deployment['image_sha256'] ?? null) !== $stored['image_sha256']) {
            throw new RuntimeException('development_execution_binding_mismatch');
        }
        $deployment = $stored;
        if (! in_array($operation, array_column($deployment['manifest']['operations'], 'key'), true)) {
            return (new Result('unsupported_operation', 'operation.unsupported'))->jsonSerialize();
        }
        $this->evidence = ['invocation_id' => bin2hex(random_bytes(16)), 'services' => [], 'proposals' => [], 'external_references' => [], 'simulated' => true, 'network_access' => false];
        $input['config'] = $configuration;
        $envelope = [
            'protocol_version' => '1', 'invocation_id' => $this->evidence['invocation_id'],
            'command_id' => $scope['command_id'] ?? 'development-command-'.bin2hex(random_bytes(8)),
            'attempt_id' => $scope['attempt_id'] ?? 'development-attempt-'.bin2hex(random_bytes(8)),
            'correlation_id' => $scope['correlation_id'] ?? 'development-correlation',
            'package_id' => $deployment['manifest']['package_id'], 'execution_revision' => $executionId,
            'connection_id' => $scope['connection_id'] ?? 'development-connection',
            'organization_id' => $scope['organization_id'] ?? 'development-organization',
            'location_id' => $scope['location_id'] ?? 'development-location',
            'environment' => 'sandbox', 'deployment_environment' => 'local', 'actor_id' => $scope['actor_id'] ?? 'development-pharmacy',
            'operation' => $operation, 'schema_version' => '1',
            'routing_revision' => (string) ($scope['routing_revision'] ?? 1),
            'configuration_revision' => (string) ($scope['configuration_revision'] ?? 1),
            'mapping_revision' => (string) ($scope['mapping_revision'] ?? 1),
            'credential_revision' => (string) ($scope['credential_revision'] ?? 1),
            'idempotency_key' => $scope['idempotency_key'] ?? 'development-key-'.bin2hex(random_bytes(12)),
            'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 20), 'request_budget' => $operation === 'inbound.decode' ? max(20, min(100, is_array($input['events'] ?? null) ? count($input['events']) : 0)) : 20,
            'input' => $input, 'service_grant' => bin2hex(random_bytes(32)),
        ];
        if (isset($scope['environment']) && $scope['environment'] !== 'sandbox') {
            throw new RuntimeException('development_sandbox_required');
        }
        $broker = new DevelopmentServices($this->state.'/services.sqlite', $envelope, $deployment, $configuration, $credentials, $resources, $this->packageOptions($deployment['manifest']['package_id']));
        $image = 'sha256:'.$deployment['image_sha256'];
        $actual = JsonDocument::decode(Process::run(['docker', 'image', 'inspect', $image], 15), true)[0] ?? [];
        if (($actual['Id'] ?? null) !== $image || ($actual['Config']['Labels']['org.uniscript.source'] ?? null) !== $deployment['source_sha256'] || ($actual['Config']['Labels']['org.uniscript.execution'] ?? null) !== $executionId) {
            throw new RuntimeException('development_image_binding_mismatch');
        }
        $command = array_merge(['docker', 'run', '--rm', '-i'], RuntimeProcess::flags(), ['--env', 'PACKAGE_ROOT=/package', '--env', 'EXECUTION_REVISION='.$executionId, '--env', 'SERVICE_TRANSPORT=stdio', '--entrypoint', 'timeout', $image, '-s', 'KILL', '30', 'php', '-d', 'display_errors=0', '-d', 'log_errors=0', '/opt/kit/bin/invoke.php']);
        $result = RuntimeProcess::invoke($command, $envelope, function (array $request) use ($broker): array {
            $this->evidence['services'][] = $request['service'].'.'.$request['action'];

            return $broker->request($request);
        });
        $this->evidence = [...$this->evidence, ...$broker->evidence()];

        return $result->jsonSerialize();
    }

    /** Contains trusted broker evidence, never package-supplied authority or credential values. */
    public function lastEvidence(): array
    {
        return $this->evidence;
    }

    /** Operator intent only; individual invocation evidence records whether a request occurred. */
    public function networkMode(): array
    {
        return ['live_sandbox_enabled' => getenv('POS_KIT_LIVE_SANDBOX') === '1', 'selected_package_id' => getenv('POS_KIT_LIVE_SANDBOX') === '1' ? (getenv('POS_KIT_LIVE_SANDBOX_PACKAGE') ?: null) : null];
    }

    public function transportBinding(string $packageId): string
    {
        $injected = $this->options['sandbox_transport'] ?? null;
        if ($injected instanceof DevelopmentSandboxTransport && $injected->packageId() === $packageId) {
            return $injected->configurationHash();
        }
        if (getenv('POS_KIT_LIVE_SANDBOX') === '1' && getenv('POS_KIT_LIVE_SANDBOX_PACKAGE') === $packageId) {
            $path = getenv('POS_KIT_SANDBOX_PROFILES') ?: '';

            return hash('sha256', 'live-sandbox:'.$packageId.':'.(is_file($path) ? hash_file('sha256', $path) : 'missing-profile'));
        }

        return hash('sha256', 'offline:'.json_encode($this->packageOptions($packageId)['profiles'] ?? [], JSON_THROW_ON_ERROR));
    }

    private function packageOptions(string $packageId): array
    {
        if (! array_key_exists('package_profiles', $this->options)) {
            return $this->options;
        }

        return [...$this->options, 'profiles' => $this->options['package_profiles'][$packageId] ?? []];
    }

    public function verifyCredentials(array $deployment, array $configuration, array $credentials, array $scope = []): array
    {
        $transport = $this->options['sandbox_transport'] ?? DevelopmentSandboxTransport::fromEnvironment($this->state.'/sandbox-transports.sqlite');
        if (! $transport instanceof DevelopmentSandboxTransport || $transport->packageId() !== ($deployment['manifest']['package_id'] ?? null)) {
            return ['verified' => false, 'simulated' => true, 'provenance' => 'offline_fixture'];
        }
        $transport->assertHostOwned($deployment['source_root'] ?? throw new RuntimeException('sandbox_execution_source_binding_missing'));

        return (new DevelopmentConnectionEvidence($transport))->verify($deployment, $configuration, $credentials, $scope);
    }

    /** Read-only local guard; ambiguous live reference evidence cannot authorize callbacks or actions. */
    public function referenceIsAmbiguous(string $handle): bool
    {
        $database = $this->state.'/sandbox-transports.sqlite';
        if (! is_file($database)) {
            return false;
        }
        $db = new \PDO('sqlite:'.$database, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sandbox_reference_owners'")->fetchColumn();
        if ($exists === false) {
            return false;
        }
        $query = $db->prepare('SELECT status FROM sandbox_reference_owners WHERE handle=?');
        $query->execute([$handle]);
        $status = $query->fetchColumn();

        return $status !== false && $status !== 'accepted';
    }

    public static function orderFixture(array $mapping = []): array
    {
        $fixture = JsonDocument::decode(file_get_contents(dirname(__DIR__).'/fixtures/order.json'), true);
        foreach ($fixture['order']['medications'] as &$medication) {
            $medication['mapping'] = $mapping;
        }

        return $fixture;
    }

    private static function execution(string $value): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{7,99}\z/D', $value)) {
            throw new InvalidArgumentException('invalid_development_execution');
        }
    }

    private static function save(string $path, array $value): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($temporary, 0600);
        rename($temporary, $path);
    }
}
