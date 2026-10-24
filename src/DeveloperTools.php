<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use RuntimeException;

/** Trusted authoring tools. These never execute package PHP on the developer's host. */
final class DeveloperTools
{
    public static function scaffold(string $identity, string $destination, string $template = 'reference', ?string $fixtureOutput = null): array
    {
        if (strlen($identity) > 200 || ! preg_match('~\A'.HostContract::read()['manifest']['package_id_pattern'].'\z~D', $identity)) {
            throw new InvalidArgumentException('Use a valid publisher/package identifier from the host contract.');
        }
        if (! in_array($template, ['starter', 'custom-ui', 'reference'], true)) {
            throw new InvalidArgumentException('Unknown template. Choose starter, custom-ui or reference.');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new InvalidArgumentException('Destination already exists; scaffolding never overwrites a project.');
        }
        if ($fixtureOutput !== null && (file_exists($fixtureOutput) || is_link($fixtureOutput) || ! is_dir(dirname($fixtureOutput)))) {
            throw new InvalidArgumentException('Create an unused companion fixture path outside the package source.');
        }
        $source = dirname(__DIR__).'/examples/'.$template;
        if (! is_dir($source)) {
            throw new RuntimeException('The selected template is unavailable in this kit checkout.');
        }
        mkdir($destination, 0700, true);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new InvalidArgumentException('Template files must be regular files.');
            }
            $relative = substr($file->getPathname(), strlen($source) + 1);
            Manifest::path($relative);
            $path = $destination.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            copy($file->getPathname(), $path);
        }
        $manifest = JsonDocument::decode(file_get_contents($destination.'/integration.json'), true);
        $manifest['package_id'] = $identity;
        [$manifest['publisher'], $name] = explode('/', $identity, 2);
        $manifest['integration_key'] = $name;
        $manifest['display_name'] = ucwords(str_replace('-', ' ', $name));
        $manifest['vendor_family'] = $manifest['publisher'];
        $manifest['version'] = '1.0.0';
        $manifest['support'] = ['email' => 'integrations@example.invalid', 'documentation' => 'https://example.invalid/integration'];
        self::json($destination.'/integration.json', $manifest);
        $composer = JsonDocument::decode(file_get_contents($destination.'/composer.json'), true);
        $composer['name'] = $identity;
        self::json($destination.'/composer.json', $composer);
        $relevant = array_intersect_key($composer, array_flip(['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
        if (isset($composer['config']['platform'])) {
            $relevant['config']['platform'] = $composer['config']['platform'];
        }
        ksort($relevant);
        self::json($destination.'/composer.lock', ['content-hash' => md5(json_encode($relevant, JSON_THROW_ON_ERROR)), 'packages' => [], 'packages-dev' => []]);
        file_put_contents($destination.'/.gitignore', ".env*\n/vendor/\n/node_modules/\n/dist/\n/coverage/\n/var/\n/dev-fixtures/\n/sandbox-profiles/\n*.zip\n*.report.json\n", LOCK_EX);
        Manifest::read($destination);
        if ($fixtureOutput !== null && $template === 'reference') {
            $fixture = JsonDocument::decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true);
            $fixture['package_id'] = $identity;
            if (isset($fixture['inbound_profile'])) {
                $fixture['inbound_profile']['package_ids'] = [$identity];
                InboundProfile::validate($fixture['inbound_profile'], $identity);
            }
            foreach ($fixture['profiles'] as &$profile) {
                if (array_key_exists('package_ids', $profile)) {
                    $profile['package_ids'] = [$identity];
                }
            }
            unset($profile);
            self::json($fixtureOutput, $fixture);
        }

        return ['package_id' => $identity, 'template' => $template, 'directory' => $destination, 'local_fixture' => $template === 'reference' ? $fixtureOutput : null];
    }

    public static function export(string $source, string $output): array
    {
        $root = realpath($source);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('Package directory does not exist.');
        }
        $outputParent = realpath(dirname($output));
        if ($outputParent === false) {
            throw new InvalidArgumentException('Create the artifact output directory first.');
        }
        $output = $outputParent.'/'.basename($output);
        if (is_link($output) || is_link($output.'.report.json') || is_dir($output) || is_dir($output.'.report.json')) {
            throw new InvalidArgumentException('Artifact targets must be regular files, never symlinks or directories.');
        }
        if (str_starts_with($output, $root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Export outside the package source directory.');
        }
        $temporary = sys_get_temp_dir().'/pos-kit-export-'.bin2hex(random_bytes(12));
        mkdir($temporary, 0700);
        try {
            $script = dirname(__DIR__).'/bin/package.php';
            $summary = JsonDocument::decode(Process::run([PHP_BINARY, $script, $root, $temporary.'/first.zip'], 30), true);
            $second = JsonDocument::decode(Process::run([PHP_BINARY, $script, $root, $temporary.'/second.zip'], 30), true);
            if (! hash_equals($summary['source_sha256'], $second['source_sha256'])) {
                throw new RuntimeException('Package source changed during the reproducibility check.');
            }
            $manifest = Archive::unpack($temporary.'/first.zip', $temporary.'/inspected', $summary['source_sha256']);
            $stagedOutput = tempnam($outputParent, '.pos-kit-export-');
            if ($stagedOutput === false || ! copy($temporary.'/first.zip', $stagedOutput) || ! rename($stagedOutput, $output)) {
                throw new RuntimeException('Cannot write the ZIP artifact.');
            }
            $contract = dirname(__DIR__).'/contracts/host-v1.json';
            $report = [
                'report_version' => 1,
                'package_id' => $manifest['package_id'],
                'version' => $manifest['version'],
                'source_sha256' => $summary['source_sha256'],
                'file_count' => $summary['file_count'],
                'contract_sha256' => is_file($contract) ? hash_file('sha256', $contract) : null,
                'checks' => ['archive_intake' => 'passed', 'deterministic_zip' => 'passed', 'isolated_runtime' => 'not_run_by_export', 'uniscript_installation' => 'not_run_by_export'],
                'scope' => 'local_source_validation_only',
                'final_approval' => false,
                'requested_host_profiles' => HostRequirements::read($root),
                'onboarding_requirements' => ['Assigned publisher namespace', 'Reviewed transport/account/inbound profiles for the vendor', 'Independent certification and internal approval/promotion'],
            ];
            self::json($output.'.report.json', $report);

            return ['zip' => $output, 'report' => $output.'.report.json', ...$report];
        } finally {
            self::removeTemporary($temporary);
        }
    }

    private static function json(string $path, array $value): void
    {
        $temporary = tempnam(dirname($path), '.pos-kit-json-');
        if ($temporary === false || file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('Cannot write generated project file.');
        }
    }

    private static function removeTemporary(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
