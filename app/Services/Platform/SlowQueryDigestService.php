<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;

/**
 * Reads MySQL Performance Schema digests for platform slow-query triage.
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
        $schema = (string) DB::getDatabaseName();

        if (! $this->performanceSchemaAvailable()) {
            return [
                'available' => false,
                'reason' => 'performance_schema.events_statements_summary_by_digest is not available on this MySQL instance.',
                'database' => $schema,
                'queries' => [],
                'slow_tables' => $this->centrixTableSizes(),
                'enable_hint' => [
                    'SET GLOBAL slow_query_log = 1;',
                    'SET GLOBAL long_query_time = 1;',
                    'Ensure performance_schema=ON in my.cnf.',
                ],
            ];
        }

        // Pull extra rows then keep only Centrix-schema digests (other DBs share the same MySQL instance).
        $fetch = min(200, max($limit * 8, 50));
        $rows = DB::select(<<<'SQL'
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
  AND DIGEST_TEXT NOT LIKE 'SET %'
  AND DIGEST_TEXT NOT LIKE 'SHOW %'
  AND DIGEST_TEXT NOT LIKE 'SELECT @@%'
  AND DIGEST_TEXT NOT LIKE 'USE %'
  AND DIGEST_TEXT NOT LIKE 'COMMIT%'
  AND DIGEST_TEXT NOT LIKE 'ROLLBACK%'
  AND DIGEST_TEXT NOT LIKE 'BEGIN%'
  AND DIGEST_TEXT NOT LIKE 'START TRANSACTION%'
  AND DIGEST_TEXT NOT LIKE '%`wpa0_%'
  AND DIGEST_TEXT NOT LIKE '%wpa0_%'
  AND (SCHEMA_NAME IS NULL OR SCHEMA_NAME = '' OR SCHEMA_NAME = ?)
ORDER BY SUM_TIMER_WAIT DESC
LIMIT ?
SQL, [$schema, $fetch]);

        $queries = [];
        $mentionedTables = [];
        foreach ($rows as $row) {
            $text = (string) ($row->digest_text ?? '');
            $rowSchema = trim((string) ($row->schema_name ?? ''));
            if (! $this->isCentrixDigest($text, $schema, $rowSchema)) {
                continue;
            }

            foreach ($this->tablesMentionedInSql($text) as $table) {
                $mentionedTables[$table] = true;
            }

            $queries[] = [
                'digest' => (string) ($row->digest ?? ''),
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
        $schema = (string) DB::getDatabaseName();
        $priority = array_fill_keys(array_map('strtolower', $priorityTables), true);

        try {
            $rows = DB::select(
                'SELECT table_name AS name,
                        ROUND((data_length + index_length) / 1024 / 1024, 1) AS mb,
                        table_rows AS approx_rows
                 FROM information_schema.tables
                 WHERE table_schema = ?
                   AND table_type = \'BASE TABLE\'
                 ORDER BY (data_length + index_length) DESC
                 LIMIT 30',
                [$schema],
            );
        } catch (\Throwable) {
            return [];
        }

        $tables = array_map(function ($row) use ($priority) {
            $name = (string) ($row->name ?? '');

            return [
                'name' => $name,
                'mb' => (float) ($row->mb ?? 0),
                'rows' => (int) ($row->approx_rows ?? 0),
                'in_slow_queries' => isset($priority[strtolower($name)]),
            ];
        }, $rows);

        usort($tables, function ($a, $b) {
            if ($a['in_slow_queries'] !== $b['in_slow_queries']) {
                return $a['in_slow_queries'] ? -1 : 1;
            }

            return $b['mb'] <=> $a['mb'];
        });

        return $tables;
    }

    protected function isCentrixDigest(string $sql, string $schema, string $rowSchema): bool
    {
        if ($rowSchema !== '' && strcasecmp($rowSchema, $schema) !== 0) {
            return false;
        }

        // Explicit other-database references: `pitchnewdb`.`table`
        if (preg_match_all('/`([a-zA-Z0-9_]+)`\s*\.\s*`/', $sql, $matches)) {
            foreach ($matches[1] as $dbName) {
                if (strcasecmp((string) $dbName, $schema) !== 0) {
                    return false;
                }
            }
        }

        // Unquoted schema.table for a different database
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*`?[a-zA-Z_][a-zA-Z0-9_]*`?/', $sql, $matches)) {
            foreach ($matches[1] as $dbName) {
                $candidate = strtolower((string) $dbName);
                if (in_array($candidate, ['performance_schema', 'information_schema', 'mysql', 'sys'], true)) {
                    continue;
                }
                if ($candidate !== strtolower($schema) && ! $this->looksLikeTableAlias($candidate, $sql)) {
                    // Only reject when it looks like a schema qualifier for a foreign DB.
                    if (preg_match('/`'.preg_quote((string) $dbName, '/').'`\s*\./i', $sql)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    protected function looksLikeTableAlias(string $name, string $sql): bool
    {
        return (bool) preg_match('/\b(?:as\s+)'.$name.'\b/i', $sql)
            || (bool) preg_match('/\bfrom\s+`?[a-z0-9_]+`?\s+'.$name.'\b/i', $sql);
    }

    /**
     * @return list<string>
     */
    protected function tablesMentionedInSql(string $sql): array
    {
        $found = [];
        foreach ($this->optimizableTables() as $table) {
            if (stripos($sql, $table) !== false) {
                $found[] = $table;
            }
        }

        if (preg_match_all('/(?:FROM|JOIN|UPDATE|INTO|TABLE)\s+`([a-zA-Z0-9_]+)`/i', $sql, $m)) {
            foreach ($m[1] as $table) {
                $found[] = strtolower((string) $table);
            }
        }

        return array_values(array_unique($found));
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
