<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/** Platform-owned source inspection. Never execute package installation hooks. */
final class Archive
{
    public static function unpack(string $archivePath, string $destination, string $expectedHash): array
    {
        if (!is_file($archivePath) || is_link($archivePath) || filesize($archivePath) > 20971520 || !hash_equals($expectedHash, hash_file('sha256', $archivePath))) {
            throw new InvalidArgumentException('source_integrity_failed');
        }
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) { throw new InvalidArgumentException('invalid_zip'); }
        $files = []; $normalized = []; $parents = []; $expanded = 0; $start = microtime(true);
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 2000) { throw new InvalidArgumentException('entry_limit'); }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) { throw new InvalidArgumentException('invalid_entry'); }
                $directory = str_ends_with($entry['name'], '/');
                $name = $directory ? substr($entry['name'], 0, -1) : $entry['name'];
                Manifest::path($name);
                $lower = strtolower($name);
                if (isset($normalized[$lower]) || (!$directory && isset($parents[$lower]))) { throw new InvalidArgumentException('path_collision'); }
                $normalized[$lower] = ['name' => $name, 'directory' => $directory];
                $parts = explode('/', $name); array_pop($parts); $parent = '';
                foreach ($parts as $part) {
                    $parent = $parent === '' ? $part : $parent.'/'.$part;
                    $key = strtolower($parent);
                    if ((isset($normalized[$key]) && (!$normalized[$key]['directory'] || $normalized[$key]['name'] !== $parent)) || (isset($parents[$key]) && $parents[$key] !== $parent)) { throw new InvalidArgumentException('path_collision'); }
                    $parents[$key] = $parent;
                }
                if ($directory && isset($parents[$lower]) && $parents[$lower] !== $name) { throw new InvalidArgumentException('path_collision'); }
                $os = 0; $attributes = 0;
                if (!$zip->getExternalAttributesIndex($index, $os, $attributes)) { throw new InvalidArgumentException('entry_attributes'); }
                $mode = ($attributes >> 16) & 0xffff; $type = $mode & 0170000;
                if (($type !== 0 && $type !== ($directory ? 0040000 : 0100000)) || (!$directory && ($mode & 0111) !== 0)) { throw new InvalidArgumentException('special_or_executable_entry'); }
                if (($entry['encryption_method'] ?? -1) !== ZipArchive::EM_NONE || !in_array($entry['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) { throw new InvalidArgumentException('unsupported_archive_method'); }
                $expanded += $entry['size'];
                if ($entry['size'] > 8388608 || $expanded > 104857600 || $entry['size'] / max(1, $entry['comp_size']) > 200 || ($directory && $entry['size'] !== 0)) { throw new InvalidArgumentException('expanded_limit'); }
                if ($directory) { continue; }
                if (preg_match('~(?:^|/)(?:vendor|node_modules|auth\.json|composer\.phar|artisan|\.env)(?:/|$)~i', $name) || preg_match('~(?:^|/)(?:env|credentials)(?:\.|$)~i', $name)) { throw new InvalidArgumentException('forbidden_source'); }
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($extension, ['php','json','md','txt','xml','csv','yaml','yml','js','jsx','ts','tsx','css','scss','html','svg','lock'], true) && !in_array(strtolower(basename($name)), ['license','notice','readme','changelog'], true)) { throw new InvalidArgumentException('unsupported_source'); }
                $contents = $zip->getFromIndex($index, 8388609);
                if ($contents === false || strlen($contents) !== $entry['size'] || hash('crc32b', $contents) !== sprintf('%08x', $entry['crc'])) { throw new InvalidArgumentException('entry_integrity'); }
                if (str_contains($contents, "\0") || preg_match('/\A(?:\x7fELF|MZ|#!)|-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----|\b(?:AKIA|ASIA)[A-Z0-9]{16}\b|\bgh[pousr]_[A-Za-z0-9]{36,}\b/', $contents)) { throw new InvalidArgumentException('binary_or_embedded_credential'); }
                if (microtime(true)-$start > 15) { throw new InvalidArgumentException('inspection_timeout'); }
                $files[$name] = $contents;
            }
        } finally { $zip->close(); }
        foreach (['integration.json','composer.json','composer.lock'] as $required) { if (!isset($files[$required])) { throw new InvalidArgumentException('missing_source_contract'); } }
        if (strlen($files['integration.json']) > 65536 || strlen($files['composer.json']) > 1048576 || strlen($files['composer.lock']) > 1048576) { throw new InvalidArgumentException('metadata_limit'); }
        if (is_dir($destination)) { throw new RuntimeException('destination_must_be_new'); }
        mkdir($destination, 0700, true);
        foreach ($files as $name => $contents) {
            $path = $destination.'/'.$name;
            if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
            file_put_contents($path, $contents, LOCK_EX); chmod($path, 0600);
        }
        $manifest = Manifest::read($destination);
        $composer = json_decode($files['composer.json'], true, 32, JSON_THROW_ON_ERROR);
        $lock = json_decode($files['composer.lock'], true, 32, JSON_THROW_ON_ERROR);
        // This local profile intentionally has no dependency resolver/network access.
        // Unsupported dependencies fail rather than pretending to certify a graph.
        if (($composer['name'] ?? null) !== $manifest['package_id'] || ($composer['type'] ?? 'library') !== 'library' || ($composer['require']['php'] ?? null) !== '^8.4') { throw new InvalidArgumentException('composer_identity'); }
        foreach (['scripts','repositories','bin','extra','replace','provide','require-dev'] as $key) { if (!empty($composer[$key])) { throw new InvalidArgumentException('unsupported_composer_hook'); } }
        if (array_diff(array_keys($composer['require']), ['php']) !== [] || ($lock['packages'] ?? null) !== [] || ($lock['packages-dev'] ?? null) !== []) { throw new InvalidArgumentException('dependency_profile_not_supported'); }
        $relevant = array_intersect_key($composer, array_flip(['name','version','require','require-dev','conflict','replace','provide','minimum-stability','prefer-stable','repositories','extra']));
        if (isset($composer['config']['platform'])) { $relevant['config']['platform'] = $composer['config']['platform']; }
        ksort($relevant);
        if (($lock['content-hash'] ?? null) !== md5(json_encode($relevant, JSON_THROW_ON_ERROR))) { throw new InvalidArgumentException('lockfile_stale'); }
        if (!hash_equals($expectedHash, hash_file('sha256', $archivePath))) { throw new InvalidArgumentException('source_changed'); }
        return $manifest;
    }
}
