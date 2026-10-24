<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Workbench;

$packageRoot = $argv[1] ?? dirname(__DIR__).'/examples/reference';
$fixturePath = $argv[2] ?? dirname(__DIR__).'/fixtures/reference.json';
$fixture = json_decode(file_get_contents($fixturePath), true, 32, JSON_THROW_ON_ERROR);
$root = sys_get_temp_dir().'/uniscript-catalog-runtime-'.bin2hex(random_bytes(8));
$workbench = new Workbench($root);
$package = $workbench->load($packageRoot);
if ($package['manifest']['package_id'] !== $fixture['package_id'] || empty($fixture['catalog'])) {
    throw new RuntimeException('Matching synthetic catalog fixture required');
}
$connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
$scope = ['connection_id' => $connection['uuid']];
$workbench->dispatch('connection.health', $scope);
$workbench->dispatch('connection.test', $scope);
$connection = $workbench->dispatch('connection.activate', $scope);
$stream = fopen('php://temp', 'r+');
fputcsv($stream, array_keys($fixture['catalog'][0]), escape: '');
foreach ($fixture['catalog'] as $record) {
    fputcsv($stream, array_values($record), escape: '');
}
rewind($stream);
$csv = stream_get_contents($stream);
fclose($stream);
$base = [...$scope, 'expected_version' => $connection['version'], 'confirmed' => true];
$batch = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => $csv]);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$check($batch['status'] === 'staged' && $batch['row_count'] === count($fixture['catalog']), 'Real package catalog preview staged');
$check($batch['rows'][0]['proposal']['mapping_values'] === $fixture['mapping'], 'Vendor-specific identifiers produced by actual handler through generic host');
$check($workbench->dispatch('catalog.stage', [...$base, 'source_csv' => $csv])['uuid'] === $batch['uuid'], 'Immutable source idempotency');
$batch = $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $batch['uuid'], 'expected_revision' => $batch['revision']]);
$check($batch['status'] === 'completed' && $batch['rows'][0]['status'] === 'applied', 'Synthetic host apply checkpoint follows actual proposal');
$items = $workbench->snapshot()['catalog']['items'];
$check(count($items) === count($fixture['catalog']) && $items[0]['status'] === 'needs_mapping' && ! $items[0]['canonical_mapped'], 'No invented clinical mapping or automatic publication');
$batch = $workbench->dispatch('catalog.rollback', [...$base, 'import_uuid' => $batch['uuid'], 'expected_revision' => $batch['revision']]);
$check($batch['status'] === 'rolled_back' && $workbench->snapshot()['catalog']['items'][0]['archived'], 'Safe rollback archives unchanged synthetic item');
echo json_encode(['package_id' => $fixture['package_id'], 'checks' => $checks, 'result' => 'passed', 'state' => $root, 'mode' => 'actual isolated package, synthetic catalog host'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
