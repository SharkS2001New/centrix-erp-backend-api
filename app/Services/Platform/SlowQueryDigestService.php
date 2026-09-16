<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;

/**
 * Reads MySQL Performance Schema digests for platform slow-query triage.
 */
class SlowQueryDigestService
{
    /**
     * @return array{available: bool, reason?: string, queries: list<array<string, mixed>>}
     */
    public function topQueries(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        if (! $this->performanceSchemaAvailable()) {
            return [
                'available' => false,
                'reason' => 'performance_schema.events_statements_summary_by_digest is not available on this MySQL instance.',
                'queries' => [],
                'enable_hint' => [
                    'SET GLOBAL slow_query_log = 1;',
                    'SET GLOBAL long_query_time = 1;',
                    'Ensure performance_schema=ON in my.cnf.',
                ],
            ];
        }

        $rows = DB::select(<<<'SQL'
SELECT
    DIGEST AS digest,
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
ORDER BY SUM_TIMER_WAIT DESC
LIMIT ?
SQL, [$limit]);

        $queries = array_map(function ($row) {
            $text = (string) ($row->digest_text ?? '');

            return [
                'digest' => (string) ($row->digest ?? ''),
                'sql' => $text,
                'exec_count' => (int) ($row->exec_count ?? 0),
                'total_sec' => (float) ($row->total_sec ?? 0),
                'avg_sec' => (float) ($row->avg_sec ?? 0),
                'max_sec' => (float) ($row->max_sec ?? 0),
                'rows_examined' => (int) ($row->rows_examined ?? 0),
                'rows_sent' => (int) ($row->rows_sent ?? 0),
                'first_seen' => (string) ($row->first_seen ?? ''),
                'last_seen' => (string) ($row->last_seen ?? ''),
            ];
        }, $rows);

        return [
            'available' => true,
            'queries' => $queries,
        ];
    }

    /**
     * Heuristic fast/permanent fix suggestions without calling the LLM.
     *
     * @return array{fast_fix: list<string>, permanent_fix: list<string>, safe_sql: list<string>}
     */
    public function suggestFixes(string $sql): array
    {
        $sqlLower = mb_strtolower($sql);
        $fast = [];
        $permanent = [];
        $safeSql = [];

        if (str_contains($sqlLower, 'stock_reservations')) {
            $fast[] = 'Confirm released holds are pruned (erp:prune-operational-data) so availability indexes stay small.';
            $permanent[] = 'Keep BranchStockService overlay batching; avoid per-row reservation subqueries in custom reports.';
            $safeSql[] = 'ANALYZE TABLE stock_reservations;';
        }

        if (str_contains($sqlLower, ' from `sales`') || str_contains($sqlLower, ' from sales') || str_contains($sqlLower, 'join `sales`')) {
            $fast[] = 'Bound the date range to ≤ 90 days (Centrix hot window). Re-run the report with last 14–30 days.';
            $permanent[] = 'Ensure composite indexes idx_sales_org_* exist and list/report queries always filter organization_id + archived + created_at.';
            $safeSql[] = 'ANALYZE TABLE sales;';
        }

        if (str_contains($sqlLower, 'inventory_transactions')) {
            $fast[] = 'Narrow stock-chain / movement reports to a shorter date window.';
            $permanent[] = 'Add/keep indexes on (reference_type, reference_id) and (branch_id, product_code, created_at); consider monthly archive later.';
            $safeSql[] = 'ANALYZE TABLE inventory_transactions;';
        }

        if (str_contains($sqlLower, 'like \'%') || str_contains($sqlLower, "like '%")) {
            $fast[] = 'Prefer prefix search (term%) over leading-wildcard LIKE %term% for lists.';
            $permanent[] = 'Use SqlLikeSearch helpers and exact order-number lookups that skip full scans.';
        }

        if ($fast === []) {
            $fast[] = 'Re-run with a shorter date range and confirm the query filters organization_id / branch_id.';
            $permanent[] = 'Capture EXPLAIN ANALYZE, add a covering composite index for the WHERE/ORDER BY columns, and cache repeated aggregates.';
            $safeSql[] = 'ANALYZE TABLE sales;';
        }

        return [
            'fast_fix' => array_values(array_unique($fast)),
            'permanent_fix' => array_values(array_unique($permanent)),
            'safe_sql' => array_values(array_unique($safeSql)),
        ];
    }

    /**
     * Allow only index/analyze DDL from platform admin.
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
            || str_starts_with($upper, 'CREATE INDEX ')
            || str_starts_with($upper, 'CREATE UNIQUE INDEX ')
            || (str_starts_with($upper, 'ALTER TABLE ') && str_contains($upper, ' ADD INDEX '))
            || (str_starts_with($upper, 'ALTER TABLE ') && str_contains($upper, ' ADD KEY '));

        if (! $allowed) {
            throw new \InvalidArgumentException(
                'Only ANALYZE TABLE and CREATE/ADD INDEX statements are allowed from platform admin.'
            );
        }

        foreach (['DROP ', 'DELETE ', 'UPDATE ', 'TRUNCATE ', 'INSERT ', 'REPLACE ', 'GRANT ', 'REVOKE ', 'SET GLOBAL'] as $blocked) {
            if (str_contains($upper, $blocked)) {
                throw new \InvalidArgumentException('Blocked keyword in SQL: '.trim($blocked));
            }
        }

        return $trimmed;
    }

    public function runSafeFixSql(string $sql): array
    {
        $safe = $this->assertSafeFixSql($sql);
        DB::statement($safe);

        return [
            'ok' => true,
            'executed' => $safe,
        ];
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
