<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\DeveloperTools;

try {
    $command = $argv[1] ?? 'help';
    $result = match ($command) {
        'new' => DeveloperTools::scaffold($argv[2] ?? '', $argv[3] ?? throw new InvalidArgumentException('Usage: new publisher/package DIRECTORY [starter|custom-ui|reference]'), $argv[4] ?? 'reference', $argv[5] ?? null),
        'package' => DeveloperTools::export($argv[2] ?? '', $argv[3] ?? throw new InvalidArgumentException('Usage: package SOURCE OUTPUT.zip')),
        'doctor' => ['php' => PHP_VERSION, 'php_supported' => PHP_VERSION_ID >= 80400, 'zip' => extension_loaded('zip'), 'json' => extension_loaded('json'), 'openssl' => extension_loaded('openssl'), 'contract' => is_file(dirname(__DIR__).'/contracts/host-v1.json'), 'private_app_required' => false],
        default => ['commands' => ['new publisher/package DIRECTORY [template]', 'package SOURCE OUTPUT.zip', 'doctor'], 'docker_quickstart' => './dev-kit up', 'docs' => 'README.md'],
    };
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
