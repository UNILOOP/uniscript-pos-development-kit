<?php

declare(strict_types=1);

namespace UniScript\Integration;

use PDO;

/** Synthetic vendor used only for local protocol and transport tests. */
final class MockPos
{
    private PDO $database;

    public function __construct(string $databasePath, private readonly string $username, private readonly string $password)
    {
        $this->database = new PDO('sqlite:'.$databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('PRAGMA busy_timeout = 5000');
        $this->database->exec('CREATE TABLE IF NOT EXISTS orders (reference TEXT PRIMARY KEY, scope TEXT NOT NULL, logical_key TEXT NOT NULL, input_sha256 TEXT NOT NULL, status TEXT NOT NULL, UNIQUE(scope, logical_key))');
        $this->database->exec('CREATE TABLE IF NOT EXISTS amendments (scope TEXT NOT NULL, reference TEXT NOT NULL, action TEXT NOT NULL, logical_key TEXT NOT NULL, input_sha256 TEXT NOT NULL, UNIQUE(scope, reference, action, logical_key))');
    }

    /** @param array<string, string> $headers @return array{status: int, body: array<string, mixed>} */
    public function handle(string $method, string $path, array $headers, string $body): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);

        if ($method === 'GET' && $path === '/health') {
            return $this->response(200, ['status' => 'ok', 'synthetic' => true]);
        }

        $expected = 'Basic '.base64_encode($this->username.':'.$this->password);

        if ($this->username === '' || $this->password === '' || ! hash_equals($expected, $headers['authorization'] ?? '')) {
            return $this->response(401, ['error' => 'unauthorized']);
        }

        if ($method === 'GET' && $path === '/connection-check') {
            $challenge = $headers['x-uniscript-challenge'] ?? '';
            $account = [];
            foreach (['vendor' => 'x-vendor-id', 'location' => 'x-location-id', 'api_network' => 'x-api-network-id'] as $field => $header) {
                $value = $headers[$header] ?? '';
                if (preg_match('/\A[\x21-\x7e]{1,200}\z/D', $value) !== 1) {
                    return $this->response(422, ['error' => 'invalid_probe_account']);
                }
                $account[$field] = $value;
            }
            if (preg_match('/\A[a-f0-9]{64}\z/D', $challenge) !== 1 || $body !== '') {
                return $this->response(422, ['error' => 'invalid_probe_challenge']);
            }

            return $this->response(200, ['protocol' => 'uniscript.synthetic-probe.v1', 'challenge' => $challenge, 'authenticated' => true, 'synthetic' => true, 'account' => $account]);
        }

        $scope = hash('sha256', $this->username.':'.($headers['x-location-id'] ?? 'synthetic-location'));

        if ($method === 'GET' && $path === '/metrics') {
            $query = $this->database->prepare('SELECT COUNT(*) FROM orders WHERE scope = ?');
            $query->execute([$scope]);
            $amendments = $this->database->prepare('SELECT COUNT(*) FROM amendments WHERE scope = ?');
            $amendments->execute([$scope]);

            return $this->response(200, ['synthetic' => true, 'orders' => (int) $query->fetchColumn(), 'amendments' => (int) $amendments->fetchColumn()]);
        }

        $key = $headers['idempotency-key'] ?? '';

        if (preg_match('/\A[\x21-\x7e]{1,200}\z/D', $key) !== 1 || strlen($body) > 1048576) {
            return $this->response(422, ['error' => 'invalid_request']);
        }

        $logicalKey = hash('sha256', $key);

        if ($method === 'GET' && $path === '/orders/reconcile') {
            $order = $this->findOrder($scope, $logicalKey);

            return $order === null ? $this->response(200, ['status' => 'not_found']) : $this->accepted($order['reference'], false);
        }

        $input = json_decode($body, true);

        if (! is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->response(422, ['error' => 'invalid_json']);
        }

        if ($method === 'POST' && in_array($path, ['/orders', '/order'], true)) {
            $fingerprint = hash('sha256', $body);
            $existing = $this->findOrder($scope, $logicalKey);

            if ($existing !== null) {
                return hash_equals($existing['input_sha256'], $fingerprint)
                    ? $this->accepted($existing['reference'], $path === '/order')
                    : $this->response(409, ['error' => 'idempotency_conflict']);
            }

            $reference = (string) random_int(100000000000, 999999999999);
            $query = $this->database->prepare('INSERT OR IGNORE INTO orders(reference, scope, logical_key, input_sha256, status) VALUES (?, ?, ?, ?, ?)');
            $query->execute([$reference, $scope, $logicalKey, $fingerprint, 'accepted']);
            $order = $this->findOrder($scope, $logicalKey);

            if ($order === null || ! hash_equals($order['input_sha256'], $fingerprint)) {
                return $this->response(409, ['error' => 'idempotency_conflict']);
            }

            if (($input['scenario'] ?? null) === 'accepted_then_lost') {
                return $this->response(504, ['status' => 'unknown', 'synthetic' => true]);
            }

            return $this->accepted($order['reference'], $path === '/order');
        }

        if ($method === 'PUT' && preg_match('~\A/order/([1-9][0-9]{0,17})/(status|shipping)\z~D', $path, $matches) === 1) {
            $order = $this->database->prepare('SELECT reference FROM orders WHERE scope = ? AND reference = ?');
            $order->execute([$scope, $matches[1]]);

            if ($order->fetchColumn() === false) {
                return $this->response(404, ['error' => 'order_not_found']);
            }

            $fingerprint = hash('sha256', $body);
            $query = $this->database->prepare('INSERT OR IGNORE INTO amendments(scope, reference, action, logical_key, input_sha256) VALUES (?, ?, ?, ?, ?)');
            $query->execute([$scope, $matches[1], $matches[2], $logicalKey, $fingerprint]);
            $existing = $this->database->prepare('SELECT input_sha256 FROM amendments WHERE scope = ? AND reference = ? AND action = ? AND logical_key = ?');
            $existing->execute([$scope, $matches[1], $matches[2], $logicalKey]);

            return hash_equals((string) $existing->fetchColumn(), $fingerprint)
                ? $this->response(200, ['status' => 'success', 'data' => ['orderId' => $matches[1]], 'synthetic' => true])
                : $this->response(409, ['error' => 'idempotency_conflict']);
        }

        return $this->response(404, ['error' => 'unsupported_mock_operation']);
    }

    /** @return array<string, mixed>|null */
    private function findOrder(string $scope, string $logicalKey): ?array
    {
        $query = $this->database->prepare('SELECT reference, input_sha256 FROM orders WHERE scope = ? AND logical_key = ?');
        $query->execute([$scope, $logicalKey]);

        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function accepted(string $reference, bool $lifeFile): array
    {
        return $this->response(200, $lifeFile
            ? ['status' => 'success', 'data' => ['orderId' => $reference], 'synthetic' => true]
            : ['status' => 'accepted', 'external_reference_handle' => 'mock-'.$reference, 'reference' => $reference, 'synthetic' => true]);
    }

    /** @param array<string, mixed> $body @return array{status: int, body: array<string, mixed>} */
    private function response(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
