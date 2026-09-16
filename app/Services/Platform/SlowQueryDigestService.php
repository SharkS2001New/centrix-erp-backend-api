<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reads MySQL Performance Schema digests for platform slow-query triage.
 *
 * Strictly scoped to the Centrix app database (DB_DATABASE / DATABASE()).
 * Shared MySQL hosts often also run WordPress and other apps — those digests
 * must never appear here.
 */
class SlowQueryDigestService
{
    /**
     * @return array{
     *   available: bool,
     *   reason?: string,
     *   database?: string,
     *   queries: list<array<string, mixed>>,
     *   slow_tables?: list<array<string, mixed>>,
     *   enable_hint?: list<string>
     * }
     */
    public function topQueries(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $schema = $this->centrixSchema();

        $slowTables = $this->centrixTableSizes();

        if (! $this->performanceSchemaAvailable()) {
            return [
                'available' => false,
                'reason' => 'performance_schema.events_statements_summary_by_digest is not available on this MySQL instance.',
                'database' => $schema,
                'queries' => [],
                'slow_tables' => $slowTables,
                'enable_hint' => [
                    'SET GLOBAL slow_query_log = 1;',
                    'SET GLOBAL long_query_time = 1;',
                    'Ensure performance_schema=ON in my.cnf.',
                ],
            ];
        }

        $knownTables = $this->centrixTableNames();
        $fetch = min(500, max($limit * 20, 100));

        // 1) Digests explicitly tagged with our schema.
        $rows = $this->fetchDigestsForSchema($schema, $fetch);

        // 2) NULL-schema digests that clearly touch our tables (agents sometimes omit SCHEMA_NAME).
        $nullRows = $this->fetchNullSchemaDigestsTouchingCentrix($schema, $knownTables, $fetch);
        $rows = array_merge($rows, $nullRows);

        $queries = [];
        $seenDigests = [];
        $mentionedTables = [];

        usort($rows, static function ($a, $b) {
            return ((float) ($b->total_sec ?? 0)) <=> ((float) ($a->total_sec ?? 0));
        });

        foreach ($rows as $row) {
            $text = trim((string) ($row->digest_text ?? ''));
            $digestId = (string) ($row->digest ?? '');
            $rowSchema = trim((string) ($row->schema_name ?? ''));

            if ($digestId !== '' && isset($seenDigests[$digestId])) {
                continue;
            }
            if ($text === '' || $this->isNoiseDigest($text)) {
                continue;
            }
            if (! $this->isCentrixDigest($text, $schema, $rowSchema, $knownTables)) {
                continue;
            }
            if (! $this->isActuallySlow($row)) {
                continue;
            }

            if ($digestId !== '') {
                $seenDigests[$digestId] = true;
            }

            foreach ($this->tablesMentionedInSql($text, $knownTables) as $table) {
                $mentionedTables[$table] = true;
            }

            $queries[] = [
                'digest' => $digestId,
                'schema' => $rowSchema !== '' ? $rowSchema : $schema,
                'sql' => $text,
                'exec_count' => (int) ($row->exec_count ?? 0),
                'total_sec' => (float) ($row->total_sec ?? 0),
                'avg_sec' => (float) ($row->avg_sec ?? 0),
                'max_sec' => (float) ($row->max_sec ?? 0),
                'rows_examined' => (int) ($row->rows_examined ?? 0),
                'rows_sent' => (int) ($row->rows_sent ?? 0),
                'first_seen' => (string) ($row->first_seen ?? ''),
                'last_seen' => (string) ($row->last_seen ?? ''),
                'cleanup_hint' => $this->cleanupHintForSql($text),
            ];

            if (count($queries) >= $limit) {
                break;
            }
        }

        // Re-rank table sizes with slow-query priority once we know mentions.
        $slowTables = $this->centrixTableSizes(array_keys($mentionedTables));

        return [
            'available' => true,
            'database' => $schema,
            'queries' => $queries,
            'slow_tables' => $slowTables,
        ];
    }

    /**
     * Largest Centrix tables, with tables seen in slow digests listed first.
     *
     * @param  list<string>  $priorityTables
     * @return list<array{name: string, mb: float, rows: int, in_slow_queries: bool}>
     */
    public function centrixTableSizes(array $priorityTables = []): array
    {
        $schema = $this->centrixSchema();
        $priority = array_fill_keys(array_map('strtolower', $priorityTables), true);
        $rows = $this->loadTableSizeRows($schema);

        $tables = array_map(function ($row) use ($priority) {
            $name = (string) ($row['name'] ?? '');

            return [
                'name' => $name,
                'mb' => (float) ($row['mb'] ?? 0),
                'rows' => (int) ($row['rows'] ?? 0),
                'in_slow_queries' => isset($priority[strtolower($name)]),
            ];
        }, $rows);

        usort($tables, function ($a, $b) {
            if ($a['in_slow_queries'] !== $b['in_slow_queries']) {
                return $a['in_slow_queries'] ? -1 : 1;
            }

            return $b['mb'] <=> $a['mb'];
        });

        return array_slice($tables, 0, 30);
    }

    /**
     * @param  list<string>  $knownTables
     */
    public function isCentrixDigest(
        string $sql,
        string $schema,
        string $rowSchema,
        array $knownTables = [],
    ): bool {
        if ($this->isNoiseDigest($sql)) {
            return false;
        }

        if ($rowSchema !== '' && strcasecmp($rowSchema, $schema) !== 0) {
            return false;
        }

        // Explicit other-database references: `pitchnewdb`.`table`
        if (preg_match_all('/`([a-zA-Z0-9_]+)`\s*\.\s*`([a-zA-Z0-9_]+)`/', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dbName = (string) ($match[1] ?? '');
                if ($dbName !== '' && strcasecmp($dbName, $schema) !== 0) {
                    return false;
                }
            }
        }

        $knownLookup = array_fill_keys(array_map('strtolower', $knownTables), true);
        $schemaLower = strtolower($schema);

        // Unquoted schema.table for a different database (not table.column).
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?/', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $left = strtolower((string) ($match[1] ?? ''));
                if ($left === '' || $left === $schemaLower) {
                    continue;
                }
                if (in_array($left, ['performance_schema', 'information_schema', 'mysql', 'sys'], true)) {
                    continue;
                }
                // Centrix table.column (e.g. sales.organization_id)
                if (isset($knownLookup[$left])) {
                    continue;
                }
                if ($this->looksLikeTableAlias($left, $sql)) {
                    continue;
                }

                return false;
            }
        }

        // NULL / empty SCHEMA_NAME: only keep if SQL mentions a known Centrix table.
        if ($rowSchema === '') {
            if ($knownTables === []) {
                return false;
            }

            return $this->mentionsKnownCentrixTable($sql, $knownTables);
        }

        return true;
    }

    public function isNoiseDigest(string $sql): bool
    {
        $trimmed = ltrim($sql);
        $upper = strtoupper($trimmed);

        foreach ([
            'SET ',
            'SHOW ',
            'USE ',
            'COMMIT',
            'ROLLBACK',
            'BEGIN',
            'START TRANSACTION',
            'SAVEPOINT ',
            'RELEASE SAVEPOINT',
            'XA ',
            'SELECT @@',
            'SELECT DATABASE(',
            'SELECT SCHEMA(',
            'SELECT CONNECTION_ID(',
            'SELECT VERSION(',
            'PREPARE ',
            'EXECUTE ',
            'DEALLOCATE ',
        ] as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return true;
            }
        }

        $lower = strtolower($trimmed);

        // WordPress / phpMyAdmin / other tenants on shared MySQL.
        foreach ([
            'wpa0_',
            'wp_',
            'wpmeta',
            'phpmyadmin',
            'pitchnewdb',
            'information_schema',
            'performance_schema',
            'mysql.',
            '`mysql`',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<object>
     */
    protected function fetchDigestsForSchema(string $schema, int $limit): array
    {
        try {
            return DB::select(<<<'SQL'
SELECT
    DIGEST AS digest,
    SCHEMA_NAME AS schema_name,
    LEFT(DIGEST_TEXT, 800) AS digest_text,
    COUNT_STAR AS exec_count,
    ROUND(SUM_TIMER_WAIT / 1e12, 3) AS total_sec,
    ROUND(AVG_TIMER_WAIT / 1e12, 3) AS avg_sec,
    ROUND(MAX_TIMER_WAIT / 1e12, 3) AS max_sec,
    SUM_ROWS_EXAMINED AS rows_examined,
    SUM_ROWS_SENT AS rows_sent,
    FIRST_SEEN AS first_seen,
    LAST_SEEN AS last_seen
FROM performance_schema.events_statements_summary_by_digest
WHERE DIGEST_TEXT IS NOT NULL
  AND SCHEMA_NAME = ?
ORDER BY SUM_TIMER_WAIT DESC
LIMIT ?
SQL, [$schema, $limit]);
        } catch (\Throwable $e) {
            Log::warning('slow_queries.schema_digests_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  list<string>  $knownTables
     * @return list<object>
     */
    protected function fetchNullSchemaDigestsTouchingCentrix(string $schema, array $knownTables, int $limit): array
    {
        if ($knownTables === []) {
            return [];
        }

        // Prefer high-signal Centrix tables so the LIKE list stays small.
        $priority = array_values(array_intersect(
            $this->optimizableTables(),
            array_map('strtolower', $knownTables),
        ));
        if ($priority === []) {
            $priority = array_slice(array_map('strtolower', $knownTables), 0, 25);
        }

        $likes = [];
        $bindings = [];
        foreach (array_slice($priority, 0, 20) as $table) {
            $likes[] = 'DIGEST_TEXT LIKE ?';
            $bindings[] = '%'.$table.'%';
        }
        if ($likes === []) {
            return [];
        }

        $likeSql = implode(' OR ', $likes);
        $bindings[] = $limit;

        try {
            return DB::select(
                <<<SQL
SELECT
    DIGEST AS digest,
    SCHEMA_NAME AS schema_name,
    LEFT(DIGEST_TEXT, 800) AS digest_text,
    COUNT_STAR AS exec_count,
    ROUND(SUM_TIMER_WAIT / 1e12, 3) AS total_sec,
    ROUND(AVG_TIMER_WAIT / 1e12, 3) AS avg_sec,
    ROUND(MAX_TIMER_WAIT / 1e12, 3) AS max_sec,
    SUM_ROWS_EXAMINED AS rows_examined,
    SUM_ROWS_SENT AS rows_sent,
    FIRST_SEEN AS first_seen,
    LAST_SEEN AS last_seen
FROM performance_schema.events_statements_summary_by_digest
WHERE DIGEST_TEXT IS NOT NULL
  AND (SCHEMA_NAME IS NULL OR SCHEMA_NAME = '')
  AND ({$likeSql})
ORDER BY SUM_TIMER_WAIT DESC
LIMIT ?
SQL,
                $bindings,
            );
        } catch (\Throwable $e) {
            Log::warning('slow_queries.null_schema_digests_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    protected function isActuallySlow(object $row): bool
    {
        $avg = (float) ($row->avg_sec ?? 0);
        $total = (float) ($row->total_sec ?? 0);
        $max = (float) ($row->max_sec ?? 0);
        $examined = (int) ($row->rows_examined ?? 0);

        // Keep genuinely expensive digests; drop micro-latency chatter.
        return $avg >= 0.05
            || $max >= 0.5
            || $total >= 5.0
            || $examined >= 100000;
    }

    protected function looksLikeTableAlias(string $name, string $sql): bool
    {
        return (bool) preg_match('/\b(?:as\s+)'.$name.'\b/i', $sql)
            || (bool) preg_match('/\bfrom\s+`?[a-z0-9_]+`?\s+'.$name.'\b/i', $sql);
    }

    /**
     * @param  list<string>  $knownTables
     * @return list<string>
     */
    protected function tablesMentionedInSql(string $sql, array $knownTables = []): array
    {
        $found = [];
        $haystack = strtolower($sql);
        foreach ($knownTables !== [] ? $knownTables : $this->optimizableTables() as $table) {
            $table = strtolower((string) $table);
            if ($table !== '' && str_contains($haystack, $table)) {
                $found[] = $table;
            }
        }

        if (preg_match_all('/(?:FROM|JOIN|UPDATE|INTO|TABLE)\s+(?:`[a-zA-Z0-9_]+`\s*\.\s*)?`([a-zA-Z0-9_]+)`/i', $sql, $m)) {
            foreach ($m[1] as $table) {
                $found[] = strtolower((string) $table);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $knownTables
     */
    protected function mentionsKnownCentrixTable(string $sql, array $knownTables): bool
    {
        $haystack = strtolower($sql);
        foreach ($knownTables as $table) {
            $table = strtolower((string) $table);
            if ($table !== '' && str_contains($haystack, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   fast_fix: list<string>,
     *   permanent_fix: list<string>,
     *   safe_sql: list<string>,
     *   platform_actions: list<array<string, mixed>>
     * }
     */
    public function suggestFixes(string $sql): array
    {
        $sqlLower = mb_strtolower($sql);
        $fast = [];
        $permanent = [];
        $safeSql = [];
        $platformActions = [];

        if (
            str_contains($sqlLower, 'hikvision_agent_commands')
            || str_contains($sqlLower, 'hikvision_access_events')
            || str_contains($sqlLower, 'employee_attendance')
            || str_contains($sqlLower, 'employee_clock_sessions')
        ) {
            $fast[] = 'Run Platform → Data retention → Run prune now (deletes expired Hikvision commands/events and old attendance). Then OPTIMIZE TABLE to reclaim disk.';
            $permanent[] = 'Keep nightly erp:prune-operational-data enabled; completed agent commands are deleted on delivery.';
            $table = $this->firstMentionedRetentionTable($sqlLower);
            if ($table) {
                $safeSql[] = "OPTIMIZE TABLE `{$table}`";
                $safeSql[] = "ANALYZE TABLE `{$table}`";
            }
            $platformActions[] = [
                'id' => 'operational_prune',
                'label' => 'Delete expired Hikvision / attendance data (operational prune)',
                'kind' => 'operational_prune',
                'body' => ['dry_run' => false, 'optimize_tables' => true],
            ];
            if ($table) {
                $platformActions[] = [
                    'id' => 'optimize_'.$table,
                    'label' => "OPTIMIZE TABLE {$table}",
                    'kind' => 'safe_sql',
                    'sql' => "OPTIMIZE TABLE `{$table}`",
                ];
            }
        }

        if (str_contains($sqlLower, 'stock_reservations')) {
            $fast[] = 'Confirm released holds are pruned (Platform → Data retention) so availability indexes stay small.';
            $permanent[] = 'Keep BranchStockService overlay batching; avoid per-row reservation subqueries in custom reports.';
            $safeSql[] = 'ANALYZE TABLE `stock_reservations`';
            $platformActions[] = [
                'id' => 'operational_prune_reservations',
                'label' => 'Prune released stock reservations & related data',
                'kind' => 'operational_prune',
                'body' => ['dry_run' => false, 'optimize_tables' => false],
            ];
        }

        if (str_contains($sqlLower, ' from `sales`') || str_contains($sqlLower, ' from sales') || str_contains($sqlLower, 'join `sales`')) {
            $fast[] = 'Bound the date range to ≤ 90 days (Centrix hot window). Re-run the report with last 14–30 days.';
            $permanent[] = 'Ensure composite indexes idx_sales_org_* exist and list/report queries always filter organization_id + archived + created_at.';
            $safeSql[] = 'ANALYZE TABLE `sales`';
        }

        if (str_contains($sqlLower, 'inventory_transactions')) {
            $fast[] = 'Narrow stock-chain / movement reports to a shorter date window.';
            $permanent[] = 'Add/keep indexes on (reference_type, reference_id) and (branch_id, product_code, created_at); consider monthly archive later.';
            $safeSql[] = 'ANALYZE TABLE `inventory_transactions`';
        }

        if (str_contains($sqlLower, 'optimize table')) {
            $fast[] = 'OPTIMIZE is slow on large tables — prune expired rows first via Data retention, then optimize once.';
            $permanent[] = 'Avoid frequent OPTIMIZE; reclaim disk only after large deletes.';
            $platformActions[] = [
                'id' => 'operational_prune_before_optimize',
                'label' => 'Prune expired data first (recommended before OPTIMIZE)',
                'kind' => 'operational_prune',
                'body' => ['dry_run' => false, 'optimize_tables' => true],
            ];
        }

        if (str_contains($sqlLower, 'like \'%') || str_contains($sqlLower, "like '%")) {
            $fast[] = 'Prefer prefix search (term%) over leading-wildcard LIKE %term% for lists.';
            $permanent[] = 'Use SqlLikeSearch helpers and exact order-number lookups that skip full scans.';
        }

        if ($fast === []) {
            $fast[] = 'Re-run with a shorter date range and confirm the query filters organization_id / branch_id.';
            $permanent[] = 'Capture EXPLAIN ANALYZE, add a covering composite index for the WHERE/ORDER BY columns, and cache repeated aggregates.';
            $safeSql[] = 'ANALYZE TABLE `sales`';
        }

        return [
            'fast_fix' => array_values(array_unique($fast)),
            'permanent_fix' => array_values(array_unique($permanent)),
            'safe_sql' => array_values(array_unique($safeSql)),
            'platform_actions' => $platformActions,
        ];
    }

    /**
     * Allow ANALYZE / INDEX DDL and OPTIMIZE on Centrix retention tables only.
     */
    public function assertSafeFixSql(string $sql): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? '');
        if ($normalized === '') {
            throw new \InvalidArgumentException('SQL is empty.');
        }

        if (str_contains($normalized, ';') && ! str_ends_with(rtrim($normalized), ';')) {
            throw new \InvalidArgumentException('Only a single SQL statement is allowed.');
        }

        $trimmed = rtrim($normalized, " \t\n\r\0\x0B;");
        $upper = strtoupper($trimmed);

        $allowed = str_starts_with($upper, 'ANALYZE TABLE ')
            || str_starts_with($upper, 'OPTIMIZE TABLE ')
            || str_starts_with($upper, 'CREATE INDEX ')
            || str_starts_with($upper, 'CREATE UNIQUE INDEX ')
            || (str_starts_with($upper, 'ALTER TABLE ') && str_contains($upper, ' ADD INDEX '))
            || (str_starts_with($upper, 'ALTER TABLE ') && str_contains($upper, ' ADD KEY '));

        if (! $allowed) {
            throw new \InvalidArgumentException(
                'Only ANALYZE TABLE, OPTIMIZE TABLE (retention tables), and CREATE/ADD INDEX are allowed.'
            );
        }

        foreach (['DROP ', 'DELETE ', 'UPDATE ', 'TRUNCATE ', 'INSERT ', 'REPLACE ', 'GRANT ', 'REVOKE ', 'SET GLOBAL'] as $blocked) {
            if (str_contains($upper, $blocked)) {
                throw new \InvalidArgumentException('Blocked keyword in SQL: '.trim($blocked));
            }
        }

        if (str_starts_with($upper, 'OPTIMIZE TABLE ')) {
            $table = $this->extractSingleTableName($trimmed);
            if (! in_array($table, $this->optimizableTables(), true)) {
                throw new \InvalidArgumentException(
                    'OPTIMIZE TABLE is only allowed for Centrix retention tables (hikvision_*, employee_attendance, etc.).'
                );
            }
        }

        return $trimmed;
    }

    public function runSafeFixSql(string $sql): array
    {
        $safe = $this->assertSafeFixSql($sql);
        if (function_exists('set_time_limit') && str_starts_with(strtoupper($safe), 'OPTIMIZE TABLE ')) {
            @set_time_limit(600);
        }
        DB::statement($safe);

        return [
            'ok' => true,
            'executed' => $safe,
        ];
    }

    /**
     * @return list<string>
     */
    public function optimizableTables(): array
    {
        return [
            'hikvision_agent_commands',
            'hikvision_access_events',
            'employee_attendance',
            'employee_clock_sessions',
            'kra_agent_commands',
            'stock_reservations',
            'audit_logs',
            'sales',
            'inventory_transactions',
            'journal_entry_lines',
        ];
    }

    protected function cleanupHintForSql(string $sql): ?array
    {
        $lower = mb_strtolower($sql);
        $table = $this->firstMentionedRetentionTable($lower);
        if (! $table) {
            return null;
        }

        return [
            'table' => $table,
            'label' => 'Clean up '.$table,
            'kind' => 'operational_prune',
        ];
    }

    protected function firstMentionedRetentionTable(string $sqlLower): ?string
    {
        foreach ($this->optimizableTables() as $table) {
            if (str_contains($sqlLower, $table)) {
                return $table;
            }
        }

        return null;
    }

    protected function extractSingleTableName(string $sql): string
    {
        if (! preg_match('/^(?:ANALYZE|OPTIMIZE)\s+TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
            throw new \InvalidArgumentException('Could not parse table name.');
        }

        return strtolower($m[1]);
    }

    protected function centrixSchema(): string
    {
        $schema = trim((string) DB::getDatabaseName());
        if ($schema !== '') {
            return $schema;
        }

        return trim((string) config('database.connections.'.config('database.default').'.database', ''));
    }

    /**
     * @return list<string>
     */
    protected function centrixTableNames(): array
    {
        $schema = $this->centrixSchema();
        try {
            $rows = DB::select(
                'SELECT table_name AS name
                 FROM information_schema.tables
                 WHERE table_schema = ?
                   AND table_type = \'BASE TABLE\'',
                [$schema],
            );
            $names = array_values(array_filter(array_map(
                static fn ($row) => strtolower((string) ($row->name ?? '')),
                $rows,
            )));
            if ($names !== []) {
                return $names;
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            $rows = DB::select('SHOW TABLES');
            $names = [];
            foreach ($rows as $row) {
                $values = array_values((array) $row);
                $name = strtolower((string) ($values[0] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return array_values(array_unique($names));
        } catch (\Throwable) {
            return $this->optimizableTables();
        }
    }

    /**
     * @return list<array{name: string, mb: float, rows: int}>
     */
    protected function loadTableSizeRows(string $schema): array
    {
        try {
            $rows = DB::select(
                'SELECT table_name AS name,
                        ROUND((COALESCE(data_length, 0) + COALESCE(index_length, 0)) / 1024 / 1024, 1) AS mb,
                        COALESCE(table_rows, 0) AS approx_rows
                 FROM information_schema.tables
                 WHERE table_schema = ?
                   AND table_type = \'BASE TABLE\'
                 ORDER BY (COALESCE(data_length, 0) + COALESCE(index_length, 0)) DESC
                 LIMIT 40',
                [$schema],
            );

            if ($rows !== []) {
                return array_map(static fn ($row) => [
                    'name' => (string) ($row->name ?? ''),
                    'mb' => (float) ($row->mb ?? 0),
                    'rows' => (int) ($row->approx_rows ?? 0),
                ], $rows);
            }
        } catch (\Throwable $e) {
            Log::warning('slow_queries.table_sizes_information_schema_failed', [
                'schema' => $schema,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback for hosts that hide information_schema sizes.
        try {
            $quoted = str_replace('`', '``', $schema);
            $rows = DB::select("SHOW TABLE STATUS FROM `{$quoted}`");
            $mapped = [];
            foreach ($rows as $row) {
                $name = (string) ($row->Name ?? $row->name ?? '');
                if ($name === '') {
                    continue;
                }
                $data = (float) ($row->Data_length ?? $row->data_length ?? 0);
                $index = (float) ($row->Index_length ?? $row->index_length ?? 0);
                $mapped[] = [
                    'name' => $name,
                    'mb' => round(($data + $index) / 1024 / 1024, 1),
                    'rows' => (int) ($row->Rows ?? $row->rows ?? 0),
                ];
            }

            usort($mapped, static fn ($a, $b) => $b['mb'] <=> $a['mb']);

            return $mapped;
        } catch (\Throwable $e) {
            Log::warning('slow_queries.table_sizes_show_status_failed', [
                'schema' => $schema,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function performanceSchemaAvailable(): bool
    {
        try {
            return (bool) DB::selectOne(
                "SELECT 1 AS ok FROM information_schema.tables
                 WHERE table_schema = 'performance_schema'
                   AND table_name = 'events_statements_summary_by_digest'
                 LIMIT 1"
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
