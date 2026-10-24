<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

/** Prepares trusted SDK extensions before networkless package source builds. */
final class StandardRuntimeImage
{
    /** @return array{id: string, reference: string, layers: list<string>} */
    public static function prepare(string $state): array
    {
        $override = getenv('PACKAGE_BASE_IMAGE');
        if (is_string($override) && $override !== '') {
            $image = self::inspect($override);
            $reference = $image['RepoDigests'][0] ?? throw new RuntimeException('base_repository_digest_required');
        } else {
            try {
                $upstream = self::inspect('php:8.4-cli-alpine');
                if (self::officialReference($upstream) === null) {
                    throw new RuntimeException('official_base_reference_required');
                }
            } catch (RuntimeException) {
                Process::run(['docker', 'pull', 'php:8.4-cli-alpine'], 180);
                $upstream = self::inspect('php:8.4-cli-alpine');
            }
            $upstreamReference = self::officialReference($upstream) ?? throw new RuntimeException('official_base_reference_required');
            $dockerfile = file_get_contents(dirname(__DIR__).'/docker/Base.Dockerfile');
            $fingerprint = hash('sha256', $dockerfile.$upstream['Id'].json_encode(HostContract::read()['runtime']['extensions'], JSON_THROW_ON_ERROR));
            $tag = 'uniscript-pos-php-standard:profile-'.substr($fingerprint, 0, 24);
            $image = null;
            try {
                $cached = self::inspect($tag);
                if (($cached['Config']['Labels']['org.uniscript.standard-profile'] ?? null) === $fingerprint) {
                    $image = $cached;
                }
            } catch (RuntimeException) {
                // A missing trusted base is built once; Docker caches its extension layer.
            }
            if ($image === null) {
                $directory = $state.'/trusted-base-'.bin2hex(random_bytes(8));
                mkdir($directory, 0700, true);
                file_put_contents($directory.'/Dockerfile', $dockerfile, LOCK_EX);
                file_put_contents($directory.'/.dockerignore', "*\n!Dockerfile\n", LOCK_EX);
                Process::run(['docker', 'build', '--build-arg', 'UPSTREAM_IMAGE='.$upstreamReference, '--label', 'org.uniscript.standard-profile='.$fingerprint, '--tag', $tag, $directory], 600);
                $image = self::inspect($tag);
                if (($image['Config']['Labels']['org.uniscript.standard-profile'] ?? null) !== $fingerprint) {
                    throw new RuntimeException('standard_base_profile_mismatch');
                }
            }
            $reference = 'uniscript-pos-php-standard:'.substr($image['Id'], 7);
            Process::run(['docker', 'tag', $image['Id'], $reference], 15);
        }
        $code = 'echo json_encode(["php"=>PHP_VERSION,"extensions"=>get_loaded_extensions()],JSON_THROW_ON_ERROR);';
        $runtime = JsonDocument::decode(Process::run(['docker', 'run', '--rm', ...RuntimeProcess::flags(), '--entrypoint', 'php', $image['Id'], '-r', $code], 30), true);
        $loaded = array_map(strtolower(...), $runtime['extensions']);
        if (! str_starts_with($runtime['php'], '8.4.') || array_diff(HostContract::read()['runtime']['extensions'], $loaded) !== []) {
            throw new RuntimeException('base_runtime_profile_incomplete');
        }

        return ['id' => $image['Id'], 'reference' => $reference, 'layers' => $image['RootFS']['Layers']];
    }

    /** @param list<string> $expected @param array<string, mixed> $image */
    public static function assertAncestry(array $expected, array $image): void
    {
        if ($expected === [] || array_slice($image['RootFS']['Layers'] ?? [], 0, count($expected)) !== $expected) {
            throw new RuntimeException('base_image_ancestry_mismatch');
        }
    }

    /** @param array<string, mixed> $image */
    public static function officialReference(array $image): ?string
    {
        foreach ($image['RepoDigests'] ?? [] as $reference) {
            if (is_string($reference) && preg_match('~\A(?:docker\.io/library/)?php@sha256:[a-f0-9]{64}\z~D', $reference) === 1) {
                return $reference;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function inspect(string $reference): array
    {
        $image = JsonDocument::decode(Process::run(['docker', 'image', 'inspect', $reference], 30), true)[0] ?? [];
        if (! is_string($image['Id'] ?? null) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image['Id']) !== 1 || ! is_array($image['RootFS']['Layers'] ?? null)) {
            throw new RuntimeException('base_image_not_content_addressed');
        }

        return $image;
    }
}
