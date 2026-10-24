<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Manifest;

$root = realpath($argv[1] ?? '') ?: throw new InvalidArgumentException('usage: php bin/package.php PACKAGE OUTPUT.zip');
$output = $argv[2] ?? throw new InvalidArgumentException('output_required');
$manifest = Manifest::read($root);
$files = [];
$source = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$developmentNames = ['.git', '.gitignore', '.gitattributes', '.gitmodules', '.github', '.editorconfig', '.vscode', '.idea', '.agents', '.codex', 'node_modules', 'tests', 'test', 'fixtures', 'dev-fixtures', 'var', 'dist', 'coverage', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'];
$filtered = new RecursiveCallbackFilterIterator($source, static fn (SplFileInfo $entry): bool => ! in_array(strtolower($entry->getFilename()), $developmentNames, true) && ! str_ends_with(strtolower($entry->getFilename()), '.report.json'));
$iterator = new RecursiveIteratorIterator($filtered);
foreach ($iterator as $file) {
    if ($file->isLink() || ! $file->isFile()) {
        throw new InvalidArgumentException('regular_files_only');
    }
    $relative = substr($file->getPathname(), strlen($root) + 1);
    Manifest::path($relative);
    if (preg_match('~(?:^|/)(?:vendor|node_modules|\.git|auth\.json|\.env)(?:/|$)~i', $relative)) {
        throw new InvalidArgumentException('forbidden_source_path');
    }
    $files[$relative] = $file->getPathname();
}
$required = [$manifest['runtime']['entrypoint']];
foreach ($manifest['operations'] as $operation) {
    $required[] = $operation['input_schema'];
    $required[] = $operation['output_schema'];
}
foreach (['configuration_schema', 'credential_schema'] as $field) {
    if (isset($manifest[$field])) {
        $required[] = $manifest[$field];
    }
}
$required = [...$required, ...array_values($manifest['resource_schemas'] ?? [])];
if (isset($manifest['ui']['entrypoint'])) {
    $required[] = $manifest['ui']['entrypoint'];
}
foreach ($required as $path) {
    if (! isset($files[$path])) {
        throw new InvalidArgumentException('runtime_file_uses_excluded_development_path');
    }
}
ksort($files, SORT_STRING);
$zip = new ZipArchive;
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('zip_create_failed');
}
foreach ($files as $name => $path) {
    $zip->addFromString($name, file_get_contents($path));
    $zip->setMtimeName($name, 315532800);
    $zip->setCompressionName($name, ZipArchive::CM_STORE);
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
$zip->close();
echo json_encode(['package_id' => $manifest['package_id'], 'version' => $manifest['version'], 'source_sha256' => hash_file('sha256', $output), 'file_count' => count($files)], JSON_THROW_ON_ERROR)."\n";
