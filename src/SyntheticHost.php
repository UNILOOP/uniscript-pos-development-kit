<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Synthetic local simulator only. Production grants and ledgers belong to the host. */
final class SyntheticHost
{
    private array $grants = [];
    private array $records = [];
    private array $orders = [];
    public int $providerSubmissions = 0;

    public function grant(array $scope, array $permissions, int $ttl = 60): string
    {
        $token = bin2hex(random_bytes(32));
        $this->grants[$token] = ['scope' => $scope, 'permissions' => $permissions, 'expires' => time()+$ttl];
        return $token;
    }

    public function transport(array $request): array
    {
        $grant=$this->authorize($request);
        $args = $request['arguments'];
        $namespace = implode(':', array_map(static fn(string $key): string => $grant['scope'][$key], ['package_id', 'connection_id', 'environment']));
        return match ($request['service'].'.'.$request['action']) {
            'storage.read' => ['record' => $this->records[$namespace.':'.$args['key']] ?? null],
            'storage.write' => $this->write($namespace, $args),
            'http.request' => $this->http($namespace, $args),
            'resources.read' => ['records' => [['id' => 'synthetic-product-a', 'name' => 'Synthetic saline', 'quantity' => ['value' => '1.00', 'unit' => 'each']]]],
            'commands.propose' => ['proposal_handle' => 'proposal-'.hash('sha256', json_encode($args, JSON_THROW_ON_ERROR)), 'applied' => false],
            default => throw new RuntimeException('service_denied'),
        };
    }

    public function authorize(array $request):array
    {
        $grant = $this->grants[$request['grant']] ?? null;
        if ($grant === null || $grant['expires'] <= time() || ($grant['scope']['invocation_id'] ?? null) !== $request['invocation_id'] || !in_array($request['service'].'.'.$request['action'], $grant['permissions'], true)) {
            throw new RuntimeException('grant_denied');
        }
        return $grant;
    }

    private function write(string $namespace, array $args): array
    {
        $key = $namespace.':'.$args['key'];
        $old = $this->records[$key] ?? ['revision' => 0];
        if (($args['expected_revision'] ?? null) !== $old['revision']) { throw new RuntimeException('stale_revision'); }
        $this->records[$key] = ['revision' => $old['revision']+1, 'value' => $args['value']];
        return $this->records[$key];
    }

    private function http(string $namespace, array $args): array
    {
        if (!in_array($args['profile'] ?? null, ['synthetic.orders', 'synthetic.reconcile'], true) || ($args['operation_key']??null) !== ($args['profile']==='synthetic.orders'?'orders.submit':'orders.reconcile') || array_intersect(array_keys($args), ['url', 'method', 'path', 'headers', 'credential']) !== []) {
            throw new RuntimeException('endpoint_denied');
        }
        $key = $namespace.':'.($args['idempotency_key'] ?? '');
        if ($args['profile'] === 'synthetic.reconcile') { return $this->orders[$key] ?? ['status' => 'not_found']; }
        if (isset($this->orders[$key])) { return $this->orders[$key]; }
        $this->providerSubmissions++;
        $response = ['status' => 'accepted', 'external_reference_handle' => 'synthetic-order-'.substr(hash('sha256', $key), 0, 16)];
        $this->orders[$key] = $response;
        if (($args['body']['scenario'] ?? null) === 'accepted_then_lost') { return ['status' => 'unknown']; }
        return $response;
    }
}
