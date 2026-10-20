<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Manifest;

$root = realpath($argv[1] ?? '') ?: throw new InvalidArgumentException('usage: php bin/package.php PACKAGE OUTPUT.zip');
$output = $argv[2] ?? throw new InvalidArgumentException('output_required');
$manifest = Manifest::read($root);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isLink() || !$file->isFile()) { throw new InvalidArgumentException('regular_files_only'); }
    $relative = substr($file->getPathname(), strlen($root)+1);
    Manifest::path($relative);
    if (preg_match('~(?:^|/)(?:vendor|node_modules|\.git|auth\.json|\.env)(?:/|$)~i', $relative)) { throw new InvalidArgumentException('forbidden_source_path'); }
    $files[$relative] = $file->getPathname();
}
ksort($files, SORT_STRING);
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('zip_create_failed'); }
foreach ($files as $name => $path) {
    $zip->addFromString($name, file_get_contents($path));
    $zip->setMtimeName($name, 315532800);
    $zip->setCompressionName($name, ZipArchive::CM_STORE);
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
$zip->close();
echo json_encode(['package_id' => $manifest['package_id'], 'version' => $manifest['version'], 'source_sha256' => hash_file('sha256', $output), 'file_count' => count($files)], JSON_THROW_ON_ERROR)."\n";
