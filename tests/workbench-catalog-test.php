<?php

declare(strict_types=1);

use UniScript\Integration\Result;
use UniScript\Integration\Workbench;
use UniScript\Integration\WorkbenchCatalog;
use UniScript\Integration\WorkbenchState;

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

final class WorkbenchCatalogFixtureRuntime
{
    private readonly WorkbenchFixtureRuntime $inner;

    public int $previews = 0;

    public ?array $override = null;

    public function __construct(string $directory)
    {
        $this->inner = new WorkbenchFixtureRuntime($directory);
    }

    public function prepare(string $source, string $execution): array
    {
        return $this->inner->prepare($source, $execution);
    }

    public function lastEvidence(): array
    {
        return $this->inner->lastEvidence();
    }

    public function invoke(array $deployment, string $operation, array $input, array $configuration, array $credentials, array $resources, array $scope): array
    {
        if ($operation !== 'catalog.preview') {
            return $this->inner->invoke($deployment, $operation, $input, $configuration, $credentials, $resources, $scope);
        }
        $this->previews++;
        if ($this->override !== null) {
            return $this->override;
        }
        $rows = $input['records'] ?? $resources['catalog']['records'] ?? [];
        $proposals = [];
        foreach ($rows as $row) {
            $proposals[] = ['source_key' => $row['source_key'], 'display_name' => $row['display_name'], 'canonical_item_handle' => $row['canonical_item_handle'] ?? null, 'mapping_values' => ['product_code' => $row['product_code'], 'schedule_code' => $row['schedule_code']], 'warnings' => empty($row['warning']) ? [] : [$row['warning']], 'credentials' => 'must-not-leak'];
        }

        return (new Result('success', 'fixture.catalog_proposed', ['proposals' => $proposals, 'applied' => false, 'raw_vendor_secret' => 'must-not-leak']))->jsonSerialize();
    }
}

function catalogCsv(int $count, string $prefix = 'item'): string
{
    $source = "source_key,display_name,product_code,schedule_code\n";
    for ($row = 1; $row <= $count; $row++) {
        $source .= $prefix.'-'.$row.',Synthetic item '.$row.','.$prefix.'-'.$row.",O\n";
    }

    return $source;
}

$root = sys_get_temp_dir().'/uniscript-workbench-catalog-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$runtime = new WorkbenchCatalogFixtureRuntime($root.'/builds');
$workbench = new Workbench($root.'/state', $runtime);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, 32, JSON_THROW_ON_ERROR);
try {
    $package = $workbench->load(dirname(__DIR__).'/examples/reference');
    $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    $id = $connection['uuid'];
    $scope = ['connection_id' => $id];
    $workbench->dispatch('connection.health', $scope);
    $workbench->dispatch('connection.test', $scope);
    $connection = $workbench->dispatch('connection.activate', $scope);
    $base = [...$scope, 'expected_version' => $connection['version'], 'confirmed' => true];
    workbenchDenied(fn () => $workbench->dispatch('catalog.stage', [...$base, 'confirmed' => false, 'source_csv' => catalogCsv(1)]), 'catalog_confirmation_or_connection_revision_required');
    workbenchDenied(fn () => $workbench->dispatch('catalog.stage', [...$base, 'expected_version' => 0, 'source_csv' => catalogCsv(1)]), 'catalog_confirmation_or_connection_revision_required');
    foreach ([['', 'catalog_source_size_or_encoding_invalid'], ["a,A\nx,y\n", 'catalog_headers_ambiguous'], ["a,b\nx\n", 'catalog_rows_invalid_or_limit_exceeded'], ["a\n".str_repeat('x', 1001)."\n", 'catalog_record_invalid']] as [$csv, $reason]) {
        workbenchDenied(fn () => WorkbenchCatalog::csv($csv), $reason);
    }
    workbenchCheck(WorkbenchCatalog::csv("\xEF\xBB\xBFsource_key,display_name\nkey,Test item\n")[0]['source_key'] === 'key', 'CSV BOM parsed with exact header validation');
    $batch = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => catalogCsv(26)]);
    workbenchCheck($batch['status'] === 'staged' && $batch['row_count'] === 26 && $batch['automatic_mapping_approval'] === false, 'Actual proposal contract staged without automatic mapping approval');
    workbenchCheck(! str_contains(json_encode($batch), 'must-not-leak'), 'Only typed proposal fields reach public import summary');
    workbenchCheck($workbench->dispatch('catalog.stage', [...$base, 'source_csv' => catalogCsv(26)])['uuid'] === $batch['uuid'] && $runtime->previews === 1, 'Immutable source import deduplicates package invocation');
    $applied = $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $batch['uuid'], 'expected_revision' => 1]);
    workbenchCheck($applied['checkpoint'] === 25 && $applied['status'] === 'applying' && count($workbench->snapshot()['catalog']['items']) === 25, 'Apply bounded to one 25-row checkpoint');
    workbenchCheck(array_all($workbench->snapshot()['catalog']['items'], static fn (array $item): bool => $item['status'] === 'needs_mapping' && ! $item['canonical_mapped']), 'Ingest does not invent canonical clinical mapping');
    workbenchDenied(fn () => $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $batch['uuid'], 'expected_revision' => 1]), 'catalog_import_scope_or_revision_conflict');
    $completed = $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $batch['uuid'], 'expected_revision' => 2]);
    workbenchCheck($completed['status'] === 'completed' && $completed['checkpoint'] === 26, 'Next checkpoint completes import');
    $rollback = $workbench->dispatch('catalog.rollback', [...$base, 'import_uuid' => $batch['uuid'], 'expected_revision' => 3]);
    workbenchCheck($rollback['status'] === 'rollback_pending' && count(array_filter($workbench->snapshot()['catalog']['items'], static fn (array $item): bool => $item['archived'])) === 25, 'Rollback bounded to 25 unchanged new items');
    $rollback = $workbench->dispatch('catalog.rollback', [...$base, 'import_uuid' => $batch['uuid'], 'expected_revision' => 4]);
    workbenchCheck($rollback['status'] === 'rolled_back', 'Remaining rollback checkpoint completes');
    $existing = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => "source_key,display_name,product_code,schedule_code\nitem-1,Renamed source,item-1,O\n"]);
    $existing = $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $existing['uuid'], 'expected_revision' => 1]);
    workbenchCheck($existing['rows'][0]['status'] === 'needs_review' && $existing['rows'][0]['reason_code'] === 'catalog_existing_item_requires_explicit_review', 'Existing or archived identities never overwritten by reimport');
    $duplicates = "source_key,display_name,product_code,schedule_code\ndup,First,dup,O\ndup,Second,dup,O\n";
    $duplicate = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => $duplicates]);
    workbenchCheck(array_all($duplicate['rows'], static fn (array $row): bool => $row['status'] === 'needs_review'), 'Duplicate source keys held for explicit review');
    $duplicate = $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $duplicate['uuid'], 'expected_revision' => 1]);
    workbenchCheck($duplicate['status'] === 'completed' && array_all($duplicate['rows'], static fn (array $row): bool => $row['status'] === 'needs_review'), 'Needs-review rows not ingested');
    $warnings = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => "source_key,display_name,product_code,schedule_code,warning\nwarn,Warning item,warn,O,catalog.synthetic_warning\n"]);
    workbenchCheck($warnings['rows'][0]['status'] === 'needs_review', 'Package warnings block automatic ingestion');
    $cancelled = $workbench->dispatch('catalog.cancel', [...$base, 'import_uuid' => $warnings['uuid'], 'expected_revision' => 1]);
    workbenchCheck($cancelled['status'] === 'cancelled', 'Pending work cancelled');
    $protected = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => catalogCsv(3, 'protected')]);
    $protected = $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $protected['uuid'], 'expected_revision' => 1]);
    $store = new WorkbenchState($root.'/state');
    $store->transaction(function (array &$state) use ($protected): void {
        $rows = $state['catalog_imports'][$protected['uuid']]['rows'];
        $state['catalog_items'][$rows[0]['item_uuid']]['version']++;
        $state['catalog_items'][$rows[1]['item_uuid']]['canonical_mapped'] = true;
        $state['orders']['synthetic-in-use'] = ['uuid' => 'synthetic-in-use', 'catalog_item_id' => $rows[2]['item_uuid']];
    });
    $protected = $workbench->dispatch('catalog.rollback', [...$base, 'import_uuid' => $protected['uuid'], 'expected_revision' => 2]);
    workbenchCheck($protected['status'] === 'rollback_requires_review' && array_all($protected['rows'], static fn (array $row): bool => $row['status'] === 'rollback_blocked'), 'Changed, mapped and in-use items preserved on rollback');
    $store->transaction(static function (array &$state): void {
        unset($state['orders']['synthetic-in-use']);
    });
    $paged = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => catalogCsv(101, 'paged')]);
    workbenchCheck(count($paged['rows']) === 100 && $paged['next_cursor'] === 100, 'Review response uses 100-row pages');
    $next = $workbench->dispatch('catalog.read', [...$scope, 'import_uuid' => $paged['uuid'], 'after_row' => 100]);
    workbenchCheck(count($next['rows']) === 1 && $next['rows'][0]['row_number'] === 101 && $next['next_cursor'] === null, 'Review cursor continues after exact last row');
    $other = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
    workbenchDenied(fn () => $workbench->dispatch('catalog.read', ['connection_id' => $other['uuid'], 'import_uuid' => $paged['uuid']]), 'catalog_import_scope_or_revision_conflict');
    $preview = $workbench->dispatch('connection.invoke', [...$scope, 'operation' => 'catalog.preview', 'input' => ['records' => $fixture['catalog']]]);
    workbenchCheck($preview['data']['proposals'][0]['source_key'] === $fixture['catalog'][0]['source_key'] && ! str_contains(json_encode($preview), 'must-not-leak'), 'Operation console exposes only typed safe catalog proposal data');
    workbenchDenied(fn () => $workbench->dispatch('connection.invoke', [...$scope, 'operation' => 'orders.status.update', 'input' => []]), 'use_scoped_order_or_callback_workflow');
    $tampered = $workbench->dispatch('catalog.stage', [...$base, 'source_csv' => catalogCsv(1, 'tampered')]);
    $sealed = $store->transaction(function (array &$state) use ($tampered, $store): string {
        $original = $state['catalog_imports'][$tampered['uuid']]['rows'][0]['proposal'];
        $value = $store->open($original);
        $value['display_name'] = 'Unauthorized changed proposal';
        $state['catalog_imports'][$tampered['uuid']]['rows'][0]['proposal'] = $store->seal($value);

        return $original;
    });
    workbenchDenied(fn () => $workbench->dispatch('catalog.apply', [...$base, 'import_uuid' => $tampered['uuid'], 'expected_revision' => 1]), 'catalog_row_source_conflict');
    $store->transaction(static function (array &$state) use ($tampered, $sealed): void {
        $state['catalog_imports'][$tampered['uuid']]['rows'][0]['proposal'] = $sealed;
    });
    workbenchCheck(! array_any($workbench->snapshot()['catalog']['items'], static fn (array $item): bool => $item['import_uuid'] === $tampered['uuid']), 'Proposal fingerprint failure commits no partial catalog item');
    $current = array_find($workbench->snapshot()['connections'], static fn (array $value): bool => $value['uuid'] === $id);
    $workbench->dispatch('connection.save', [...$scope, 'expected_version' => $current['version'], 'config' => [...$fixture['configuration'], 'display_label' => 'Changed catalog context']]);
    $current = array_find($workbench->snapshot()['connections'], static fn (array $value): bool => $value['uuid'] === $id);
    workbenchDenied(fn () => $workbench->dispatch('catalog.cancel', [...$base, 'expected_version' => $current['version'], 'import_uuid' => $paged['uuid'], 'expected_revision' => $paged['revision']]), 'catalog_import_scope_or_revision_conflict');
    echo 'Workbench catalog import checkpoints, typed previews and rollback guards: '.$checks." checks passed.\n";
} finally {
    workbenchRemove($root);
}
