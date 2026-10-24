<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\HostContract;

$root = dirname(__DIR__);
$checks = 0;
$files = [$root.'/README.md', ...glob($root.'/docs/*.md')];
$combined = '';
foreach ($files as $file) {
    $contents = file_get_contents($file);
    $combined .= $contents."\n";
    preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $contents, $links);
    foreach ($links[1] as $target) {
        if (preg_match('~\A(?:https?://|#)~', $target)) {
            continue;
        }
        $target = explode('#', $target, 2)[0];
        if (! is_file(dirname($file).'/'.$target)) {
            throw new RuntimeException('Broken local documentation link: '.basename($file).' -> '.$target);
        }
        $checks++;
    }
}
foreach (HostContract::read()['runtime']['host_services'] as $function) {
    if (! str_contains($combined, '`'.$function.'`')) {
        throw new RuntimeException('Missing host function documentation: '.$function);
    }
    $checks++;
}
foreach (HostContract::read()['ui']['surfaces'] as $surface => $operations) {
    foreach ([$surface, ...$operations['queries'], ...$operations['actions']] as $name) {
        if (! str_contains($combined, '`'.$name.'`')) {
            throw new RuntimeException('Missing bridge documentation: '.$name);
        }
        $checks++;
    }
}
foreach (HostContract::read()['runtime']['result_kinds'] as $kind) {
    if (! str_contains(file_get_contents($root.'/docs/runtime.md'), '`'.$kind.'`')) {
        throw new RuntimeException('Missing result kind documentation: '.$kind);
    }
    $checks++;
}
echo json_encode(['result' => 'passed', 'assertions' => $checks], JSON_THROW_ON_ERROR)."\n";
