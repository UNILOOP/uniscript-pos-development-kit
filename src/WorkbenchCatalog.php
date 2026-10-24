<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

/** Bounded synthetic model of the host's reviewed catalog import checkpoints. */
final class WorkbenchCatalog
{
    public function __construct(private readonly WorkbenchState $vault) {}

    public static function csv(string $source): array
    {
        if ($source === '' || strlen($source) > 524288 || preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('catalog_source_size_or_encoding_invalid');
        }
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $source);
            rewind($stream);
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers)) {
                throw new InvalidArgumentException('catalog_headers_invalid');
            }
            $headers = array_map(static fn (mixed $header): mixed => is_string($header) ? preg_replace('/\A\xEF\xBB\xBF/', '', $header) : $header, $headers);
            self::headers($headers);
            $records = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($headers) || count($records) >= 1000) {
                    throw new InvalidArgumentException('catalog_rows_invalid_or_limit_exceeded');
                }
                $records[] = array_combine($headers, $row);
            }
            self::records($records);

            return $records;
        } finally {
            fclose($stream);
        }
    }

    public function stage(array &$state, array $connection, array $records, int $expectedVersion, callable $preview, callable $validateMapping): array
    {
        self::context($connection);
        self::records($records);
        if ($expectedVersion !== $connection['version']) {
            throw new InvalidArgumentException('catalog_source_or_connection_revision_invalid');
        }
        $source = hash('sha256', self::canonical([$connection['uuid'], $connection['execution_revision'], $connection['configuration_revision'], $connection['credential_revision'], $expectedVersion, $records]));
        foreach ($state['catalog_imports'] ?? [] as $existing) {
            if ($existing['connection_id'] === $connection['uuid'] && hash_equals($existing['source_sha256'], $source)) {
                return $this->summary($existing);
            }
        }
        $result = $preview($records);
        if (($result['kind'] ?? null) === 'unsupported_operation') {
            throw new InvalidArgumentException('catalog_preview_unsupported');
        }
        $proposals = $result['data']['proposals'] ?? null;
        if (($result['kind'] ?? null) !== 'success' || ! is_array($proposals) || ! array_is_list($proposals) || count($proposals) !== count($records)) {
            throw new InvalidArgumentException('catalog_preview_unusable');
        }
        $id = WorkbenchState::uuid();
        $rows = [];
        $seen = [];
        foreach ($proposals as $index => $proposal) {
            $value = self::proposal($proposal);
            if ($value['mapping_values'] !== null) {
                $validateMapping($value['mapping_values']);
            }
            $duplicate = isset($seen[$value['source_key']]);
            if ($duplicate) {
                $value['warnings'][] = 'catalog.duplicate_source_key';
                $rows[$seen[$value['source_key']]]['status'] = 'needs_review';
                $rows[$seen[$value['source_key']]]['reason_code'] = 'catalog_duplicate_source_key';
            } else {
                $seen[$value['source_key']] = $index;
            }
            $rows[] = ['uuid' => WorkbenchState::uuid(), 'row_number' => $index + 1, 'proposal' => $this->vault->seal($value), 'proposal_sha256' => hash('sha256', self::canonical($value)), 'status' => $value['warnings'] === [] ? 'pending' : 'needs_review', 'reason_code' => $value['warnings'] === [] ? null : ($duplicate ? 'catalog_duplicate_source_key' : 'catalog_row_requires_review'), 'item_uuid' => null, 'applied_item_version' => null];
        }
        $state['catalog_imports'][$id] = ['uuid' => $id, 'connection_id' => $connection['uuid'], 'execution_revision' => $connection['execution_revision'], 'configuration_revision' => $connection['configuration_revision'], 'configuration_sha256' => hash('sha256', self::canonical($connection['config'])), 'source_sha256' => $source, 'status' => 'staged', 'revision' => 1, 'checkpoint' => 0, 'created_at' => gmdate('c'), 'rows' => $rows];

        return $this->summary($state['catalog_imports'][$id]);
    }

    public function mutate(array &$state, array $connection, string $id, int $expectedRevision, string $action, bool $setupReady = true): array
    {
        self::context($connection);
        $batch = &$state['catalog_imports'][$id];
        if (! is_array($batch) || $batch['connection_id'] !== $connection['uuid'] || $batch['revision'] !== $expectedRevision || $batch['execution_revision'] !== $connection['execution_revision'] || ! hash_equals($batch['configuration_sha256'], hash('sha256', self::canonical($connection['config'])))) {
            throw new InvalidArgumentException('catalog_import_scope_or_revision_conflict');
        }
        if ($action === 'cancel') {
            if (! in_array($batch['status'], ['staged', 'applying'], true)) {
                throw new InvalidArgumentException('catalog_import_not_cancellable');
            }
            $batch['status'] = 'cancelled';
        } elseif ($action === 'apply') {
            if (! $setupReady) {
                throw new InvalidArgumentException('fresh_health_and_test_required');
            }
            if (! in_array($batch['status'], ['staged', 'applying'], true)) {
                throw new InvalidArgumentException('catalog_import_not_applicable');
            }
            $end = min(count($batch['rows']), $batch['checkpoint'] + 25);
            for ($index = $batch['checkpoint']; $index < $end; $index++) {
                $row = &$batch['rows'][$index];
                if ($row['status'] === 'pending') {
                    $this->applyRow($state, $connection, $batch['uuid'], $row);
                }
                $batch['checkpoint'] = $row['row_number'];
            }
            unset($row);
            $batch['status'] = $batch['checkpoint'] < count($batch['rows']) ? 'applying' : 'completed';
        } elseif ($action === 'rollback') {
            if (! in_array($batch['status'], ['completed', 'cancelled', 'rollback_pending'], true)) {
                throw new InvalidArgumentException('catalog_import_not_rollbackable');
            }
            $processed = 0;
            foreach ($batch['rows'] as &$row) {
                if ($row['status'] !== 'applied' || $processed++ >= 25) {
                    continue;
                }
                $item = &$state['catalog_items'][$row['item_uuid']];
                $inUse = array_any($state['orders'], static fn (array $order): bool => ($order['catalog_item_id'] ?? null) === $row['item_uuid']);
                $mapped = array_any($state['connections'], static fn (array $candidate): bool => isset($candidate['mappings'][$row['item_uuid']]));
                if (! is_array($item) || $item['connection_id'] !== $connection['uuid'] || $item['version'] !== $row['applied_item_version'] || $item['status'] !== 'needs_mapping' || $item['canonical_mapped'] || $inUse || $mapped) {
                    $row['status'] = 'rollback_blocked';
                    $row['reason_code'] = 'catalog_item_changed_or_in_use';
                } else {
                    $item['status'] = 'archived';
                    $item['version']++;
                    $item['archived'] = true;
                    $row['status'] = 'rolled_back';
                }
            }
            unset($row, $item);
            $remaining = array_any($batch['rows'], static fn (array $row): bool => $row['status'] === 'applied');
            $blocked = array_any($batch['rows'], static fn (array $row): bool => $row['status'] === 'rollback_blocked');
            $batch['status'] = $remaining ? 'rollback_pending' : ($blocked ? 'rollback_requires_review' : 'rolled_back');
        } else {
            throw new InvalidArgumentException('catalog_action_unsupported');
        }
        $batch['revision']++;

        return $this->summary($batch);
    }

    public function read(array $state, string $connectionId, string $batchId, int $afterRow = 0): array
    {
        $batch = $state['catalog_imports'][$batchId] ?? null;
        if (! is_array($batch) || $batch['connection_id'] !== $connectionId || $afterRow < 0 || $afterRow > 1000) {
            throw new InvalidArgumentException('catalog_import_scope_or_revision_conflict');
        }

        return $this->summary($batch, $afterRow);
    }

    public function snapshot(array $state): array
    {
        $batches = [];
        foreach (array_reverse($state['catalog_imports'] ?? [], true) as $batch) {
            if (count($batches[$batch['connection_id']] ?? []) < 20) {
                $batches[$batch['connection_id']][] = $this->summary($batch);
            }
        }

        return ['imports' => $batches, 'items' => array_values($state['catalog_items'] ?? []), 'canonical_mapping_simulated' => false];
    }

    public static function proposal(mixed $proposal, int $maximumWarnings = 20): array
    {
        if (! is_array($proposal) || ! is_string($proposal['source_key'] ?? null) || ! preg_match('/\A[\x21-\x7e]{1,200}\z/D', $proposal['source_key']) || ! is_string($proposal['display_name'] ?? null) || $proposal['display_name'] === '' || preg_match('//u', $proposal['display_name']) !== 1 || preg_match_all('/./us', $proposal['display_name']) > 240 || ! is_array($proposal['warnings'] ?? null) || ! array_is_list($proposal['warnings']) || count($proposal['warnings']) > $maximumWarnings || (isset($proposal['canonical_item_handle']) && (! is_string($proposal['canonical_item_handle']) || ! preg_match('/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/iD', $proposal['canonical_item_handle']))) || (isset($proposal['mapping_values']) && (! is_array($proposal['mapping_values']) || ! JsonValue::object($proposal['mapping_values'])))) {
            throw new InvalidArgumentException('catalog_proposal_invalid');
        }
        foreach ($proposal['warnings'] as $warning) {
            if (! is_string($warning) || ! preg_match('/\A[a-z0-9_.:-]{1,100}\z/D', $warning)) {
                throw new InvalidArgumentException('catalog_warning_invalid');
            }
        }

        return ['source_key' => $proposal['source_key'], 'display_name' => $proposal['display_name'], 'canonical_item_handle' => $proposal['canonical_item_handle'] ?? null, 'mapping_values' => $proposal['mapping_values'] ?? null, 'warnings' => $proposal['warnings']];
    }

    private function applyRow(array &$state, array $connection, string $importId, array &$row): void
    {
        $value = $this->proposalValue($row);
        $identity = strtolower(trim($value['source_key']));
        $existing = array_any($state['catalog_items'] ?? [], static fn (array $item): bool => $item['connection_id'] === $connection['uuid'] && $item['source_identity'] === $identity);
        if ($existing || $value['canonical_item_handle'] !== null) {
            $row['status'] = 'needs_review';
            $row['reason_code'] = 'catalog_existing_item_requires_explicit_review';

            return;
        }
        $id = WorkbenchState::uuid();
        $state['catalog_items'][$id] = ['uuid' => $id, 'connection_id' => $connection['uuid'], 'organization_id' => $connection['organization_id'], 'location_id' => $connection['location_id'], 'environment' => $connection['environment'], 'source_identity' => $identity, 'source_key' => $value['source_key'], 'display_name' => $value['display_name'], 'version' => 1, 'status' => 'needs_mapping', 'canonical_mapped' => false, 'archived' => false, 'import_uuid' => $importId, 'synthetic_only' => true];
        $row['status'] = 'applied';
        $row['item_uuid'] = $id;
        $row['applied_item_version'] = 1;
    }

    private function summary(array $batch, int $afterRow = 0): array
    {
        $rows = array_values(array_filter($batch['rows'], static fn (array $row): bool => $row['row_number'] > $afterRow));
        $page = array_slice($rows, 0, 100);

        return ['uuid' => $batch['uuid'], 'status' => $batch['status'], 'revision' => $batch['revision'], 'checkpoint' => $batch['checkpoint'], 'row_count' => count($batch['rows']), 'created_at' => $batch['created_at'], 'next_cursor' => count($rows) > 100 ? $page[array_key_last($page)]['row_number'] : null, 'rows' => array_map(fn (array $row): array => ['uuid' => $row['uuid'], 'row_number' => $row['row_number'], 'status' => $row['status'], 'reason_code' => $row['reason_code'], 'proposal' => $this->proposalValue($row)], $page), 'automatic_mapping_approval' => false];
    }

    private function proposalValue(array $row): array
    {
        $value = $this->vault->open($row['proposal']);
        if (! hash_equals($row['proposal_sha256'], hash('sha256', self::canonical($value)))) {
            throw new InvalidArgumentException('catalog_row_source_conflict');
        }

        return self::proposal($value, 21);
    }

    private static function context(array $connection): void
    {
        if (! in_array($connection['status'], ['draft', 'active'], true) || $connection['environment'] !== 'sandbox') {
            throw new InvalidArgumentException('package_catalog_import_denied');
        }
    }

    private static function records(array $records): void
    {
        if (! array_is_list($records) || $records === [] || count($records) > 1000 || strlen(json_encode($records, JSON_THROW_ON_ERROR)) > 786432) {
            throw new InvalidArgumentException('catalog_source_or_connection_revision_invalid');
        }
        foreach ($records as $record) {
            if (! is_array($record) || array_is_list($record)) {
                throw new InvalidArgumentException('catalog_record_invalid');
            }
            self::headers(array_keys($record));
            foreach ($record as $value) {
                if ((! is_scalar($value) && $value !== null) || (is_string($value) && (strlen($value) > 1000 || preg_match('//u', $value) !== 1))) {
                    throw new InvalidArgumentException('catalog_record_invalid');
                }
            }
        }
    }

    private static function headers(array $headers): void
    {
        if ($headers === [] || count($headers) > 50) {
            throw new InvalidArgumentException('catalog_headers_invalid');
        }
        $seen = [];
        foreach ($headers as $header) {
            if (! is_string($header) || strlen($header) > 80) {
                throw new InvalidArgumentException('catalog_headers_invalid');
            }
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($header)), '_');
            if ($key === '' || isset($seen[$key])) {
                throw new InvalidArgumentException('catalog_headers_ambiguous');
            }
            $seen[$key] = true;
        }
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return json_encode(array_map(self::canonical(...), $value), JSON_THROW_ON_ERROR);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
