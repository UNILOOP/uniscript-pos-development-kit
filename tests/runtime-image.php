<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\StandardRuntimeImage;

$digest = str_repeat('a', 64);
$expected = 'php@sha256:'.$digest;
$checks = 0;
foreach ([[$expected], ['registry.example.invalid/private@sha256:'.$digest, $expected]] as $references) {
    if (StandardRuntimeImage::officialReference(['RepoDigests' => $references]) !== $expected) {
        throw new RuntimeException('Default base chose a private alias instead of the official PHP reference.');
    }
    $checks++;
}
foreach ([[], ['registry.example.invalid/php@sha256:'.$digest], ['php:8.4-cli-alpine'], ['php@sha256:invalid']] as $references) {
    if (StandardRuntimeImage::officialReference(['RepoDigests' => $references]) !== null) {
        throw new RuntimeException('An unpinned or unapproved default base was accepted.');
    }
    $checks++;
}
StandardRuntimeImage::assertAncestry(['base'], ['RootFS' => ['Layers' => ['base', 'sdk', 'source']]]);
$checks++;
foreach ([[], ['other']] as $prefix) {
    try {
        StandardRuntimeImage::assertAncestry($prefix, ['RootFS' => ['Layers' => ['base', 'sdk', 'source']]]);
        throw new RuntimeException('Unmatched base layers accepted.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'base_image_ancestry_mismatch') {
            throw $exception;
        }
        $checks++;
    }
}
echo json_encode(['result' => 'passed', 'assertions' => $checks], JSON_THROW_ON_ERROR)."\n";
