<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Private, locked local state. Never mount this directory into a package container. */
final class WorkbenchState
{
    private readonly string $key;

    public function __construct(public readonly string $directory)
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('workbench_state_unavailable');
        }
        chmod($directory, 0700);
        $keyFile = $directory.'/vault.key';
        $lock = fopen($directory.'/initialize.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('workbench_state_unavailable');
        }
        try {
            if (! is_file($keyFile)) {
                file_put_contents($keyFile, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), LOCK_EX);
                chmod($keyFile, 0600);
            }
            $key = file_get_contents($keyFile);
            if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('workbench_vault_unavailable');
            }
            $this->key = $key;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function transaction(callable $callback): mixed
    {
        $lock = fopen($this->directory.'/state.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('workbench_state_unavailable');
        }
        try {
            $path = $this->directory.'/state.json';
            $state = is_file($path) ? json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : self::initial();
            if (! is_array($state) || ($state['version'] ?? null) !== 1) {
                throw new RuntimeException('workbench_state_version');
            }
            $result = $callback($state);
            $temporary = $this->directory.'/state.'.bin2hex(random_bytes(8)).'.tmp';
            file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            chmod($temporary, 0600);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('workbench_state_write');
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function seal(array $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR), $nonce, $this->key));
    }

    public function open(string $sealed): array
    {
        $bytes = base64_decode($sealed, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('workbench_vault_invalid');
        }
        $value = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if ($value === false) {
            throw new RuntimeException('workbench_vault_invalid');
        }

        return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    private static function initial(): array
    {
        return ['version' => 1, 'packages' => [], 'connections' => [], 'account_bindings' => [], 'orders' => [], 'endpoints' => [], 'receipts' => [], 'sessions' => [], 'events' => []];
    }
}
