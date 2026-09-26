<?php

namespace App\Services\Platform;

use App\Models\SystemIssueReport;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a pasted Centrix API URL into route context, related digests,
 * client-reported slow hits, and actionable slowdown hypotheses.
 */
class SlowApiUrlAnalyzer
{
    public function __construct(
        protected SlowQueryDigestService $digests,
    ) {}

    /**
     * @return array{
     *   input: string,
     *   method: string,
     *   path: string,
     *   query: array<string, mixed>,
     *   route: array<string, mixed>|null,
     *   related_issues: list<array<string, mixed>>,
     *   related_digests: list<array<string, mixed>>,
     *   hypotheses: list<string>,
     *   fast_fix: list<string>,
     *   permanent_fix: list<string>,
     *   safe_sql: list<string>,
     *   platform_actions: list<array<string, mixed>>,
     *   table_hints: list<string>
     * }
     */
    public function analyze(string $rawInput, ?string $methodHint = null): array
    {
        $parsed = $this->parseInput($rawInput, $methodHint);
        $routeInfo = $this->matchRoute($parsed['method'], $parsed['path']);
        $tableHints = $this->tableHintsForPath($parsed['path'], $routeInfo);
        $relatedIssues = $this->relatedSlowIssues($parsed['path'], $parsed['method']);
        $relatedDigests = $this->relatedDigests($tableHints);
        $advice = $this->heuristicAdvice($parsed, $routeInfo, $tableHints, $relatedIssues, $relatedDigests);

        return [
            'input' => $rawInput,
            'method' => $parsed['method'],
            'path' => $parsed['path'],
            'query' => $parsed['query'],
            'route' => $routeInfo,
            'related_issues' => $relatedIssues,
            'related_digests' => $relatedDigests,
            'table_hints' => $tableHints,
            ...$advice,
        ];
    }

    /**
     * @return array{method: string, path: string, query: array<string, mixed>}
     */
    public function parseInput(string $raw, ?string $methodHint = null): array
    {
        $trimmed = trim($raw);
        $method = strtoupper(trim((string) ($methodHint ?: 'GET')));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            $method = 'GET';
        }

        // "GET /api/v1/sales/orders?page=1"
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\s+(.+)$/i', $trimmed, $m)) {
            $method = strtoupper($m[1]);
            $trimmed = trim($m[2]);
        }

        $path = $trimmed;
        $query = [];

        if (preg_match('#^https?://#i', $trimmed)) {
            $parts = parse_url($trimmed);
            $path = (string) ($parts['path'] ?? '/');
            if (! empty($parts['query'])) {
                parse_str((string) $parts['query'], $query);
            }
        } elseif (str_contains($trimmed, '?')) {
            [$path, $qs] = explode('?', $trimmed, 2);
            parse_str($qs, $query);
        }

        $path = '/'.ltrim($path, '/');
        $path = $this->normalizeApiPath($path);

        return [
            'method' => $method,
            'path' => $path,
            'query' => is_array($query) ? $query : [],
        ];
    }

    public function normalizeApiPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        // Strip duplicate slashes
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        if (str_starts_with($path, '/api/v1/')) {
            return $path;
        }
        if (str_starts_with($path, '/v1/')) {
            return '/api'.$path;
        }
        if (str_starts_with($path, '/api/')) {
            return $path;
        }

        // Bare resource path e.g. /sales/orders → /api/v1/sales/orders
        return '/api/v1'.($path === '/' ? '' : $path);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function matchRoute(string $method, string $path): ?array
    {
        try {
            $request = Request::create($path, $method);
            /** @var Route $route */
            $route = app('router')->getRoutes()->match($request);
        } catch (\Throwable $e) {
            Log::info('slow_api_url.route_unmatched', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $action = $route->getActionName();
        $controller = null;
        $actionMethod = null;
        if (is_string($action) && str_contains($action, '@')) {
            [$controller, $actionMethod] = explode('@', $action, 2);
        } elseif (is_string($action) && str_contains($action, '::')) {
            [$controller, $actionMethod] = explode('::', $action, 2);
        }

        return [
            'uri' => '/'.$route->uri(),
            'name' => $route->getName(),
            'action' => $action,
            'controller' => $controller,
            'action_method' => $actionMethod,
            'middleware' => array_values(array_filter(array_map(
                static fn ($m) => is_string($m) ? $m : null,
                $route->gatherMiddleware(),
            ))),
            'parameters' => $route->parameters(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $routeInfo
     * @return list<string>
     */
    protected function tableHintsForPath(string $path, ?array $routeInfo): array
    {
        $hay = strtolower($path.' '.($routeInfo['controller'] ?? '').' '.($routeInfo['action'] ?? ''));
        $map = [
            'sales' => ['sales', 'sale_items', 'sale_payments'],
            'order' => ['sales', 'sale_items'],
            'debtor' => ['sales', 'sale_payments'],
            'pos' => ['sales', 'sale_items'],
            'product' => ['products', 'product_branch_stocks'],
            'inventor' => ['inventory_transactions', 'product_branch_stocks', 'stock_reservations'],
            'stock' => ['product_branch_stocks', 'inventory_transactions', 'stock_reservations'],
            'lpo' => ['lpo_mst', 'lpo_dtl'],
            'supplier' => ['suppliers', 'lpo_mst'],
            'customer' => ['customers', 'sales'],
            'receipt' => ['inventory_receipts', 'inventory_transactions'],
            'attendance' => ['employee_attendance', 'employee_clock_sessions', 'hikvision_access_events'],
            'hikvision' => ['hikvision_agent_commands', 'hikvision_access_events'],
            'payroll' => ['employee_payroll_runs', 'employees'],
            'employee' => ['employees', 'employee_attendance'],
            'journal' => ['journal_entries', 'journal_entry_lines'],
            'expense' => ['expenses'],
            'hospitalit' => ['hospitality_folios', 'hospitality_reservations'],
            'folio' => ['hospitality_folios'],
            'reservation' => ['hospitality_reservations'],
            'report' => ['sales', 'inventory_transactions', 'journal_entry_lines'],
            'vat' => ['sales'],
            'route' => ['routes', 'route_customers', 'sales'],
            'fulfillment' => ['sales', 'trips'],
            'audit' => ['audit_logs'],
        ];

        $found = [];
        foreach ($map as $needle => $tables) {
            if (str_contains($hay, $needle)) {
                foreach ($tables as $table) {
                    $found[$table] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function relatedSlowIssues(string $path, string $method): array
    {
        $needle = $this->pathNeedle($path);
        if ($needle === '') {
            return [];
        }

        try {
            return SystemIssueReport::query()
                ->where('kind', 'slow')
                ->whereIn('status', ['open', 'acknowledged'])
                ->where(function ($q) use ($needle, $path) {
                    $q->where('api_path', 'like', '%'.$needle.'%')
                        ->orWhere('page_url', 'like', '%'.$needle.'%')
                        ->orWhere('api_path', $path);
                })
                ->when($method !== '', function ($q) use ($method) {
                    $q->where(function ($inner) use ($method) {
                        $inner->whereNull('http_method')
                            ->orWhere('http_method', $method)
                            ->orWhere('http_method', strtolower($method));
                    });
                })
                ->orderByDesc('created_at')
                ->limit(8)
                ->get(['id', 'api_path', 'http_method', 'duration_ms', 'message', 'status', 'created_at', 'organization_id'])
                ->map(static function (SystemIssueReport $row) {
                    return [
                        'id' => $row->id,
                        'api_path' => $row->api_path,
                        'http_method' => $row->http_method,
                        'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                        'message' => $row->message,
                        'status' => $row->status,
                        'created_at' => optional($row->created_at)?->toIso8601String(),
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            Log::warning('slow_api_url.issues_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  list<string>  $tableHints
     * @return list<array<string, mixed>>
     */
    protected function relatedDigests(array $tableHints): array
    {
        $top = $this->digests->topQueries(25);
        $queries = $top['queries'] ?? [];
        if ($queries === [] || $tableHints === []) {
            return array_slice($queries, 0, 5);
        }

        $matched = [];
        foreach ($queries as $row) {
            $sql = strtolower((string) ($row['sql'] ?? ''));
            foreach ($tableHints as $table) {
                if (str_contains($sql, strtolower($table))) {
                    $matched[] = $row;
                    break;
                }
            }
        }

        return array_slice($matched !== [] ? $matched : $queries, 0, 8);
    }

    /**
     * @param  array{method: string, path: string, query: array<string, mixed>}  $parsed
     * @param  array<string, mixed>|null  $routeInfo
     * @param  list<string>  $tableHints
     * @param  list<array<string, mixed>>  $relatedIssues
     * @param  list<array<string, mixed>>  $relatedDigests
     * @return array{
     *   hypotheses: list<string>,
     *   fast_fix: list<string>,
     *   permanent_fix: list<string>,
     *   safe_sql: list<string>,
     *   platform_actions: list<array<string, mixed>>
     * }
     */
    protected function heuristicAdvice(
        array $parsed,
        ?array $routeInfo,
        array $tableHints,
        array $relatedIssues,
        array $relatedDigests,
    ): array {
        $path = strtolower($parsed['path']);
        $query = $parsed['query'];
        $hypotheses = [];
        $fast = [];
        $permanent = [];
        $safeSql = [];
        $actions = [];

        if ($routeInfo === null) {
            $hypotheses[] = 'No Laravel route matched this path — confirm it is a Centrix `/api/v1/...` endpoint (method + path).';
            $fast[] = 'Re-paste the full browser Network URL including `/api/v1/` and the HTTP method.';
        } else {
            $hypotheses[] = 'Matched '.$parsed['method'].' '.($routeInfo['uri'] ?? $parsed['path'])
                .' → '.($routeInfo['action'] ?? 'unknown action').'.';
        }

        if ($relatedIssues !== []) {
            $avgMs = collect($relatedIssues)
                ->pluck('duration_ms')
                ->filter(static fn ($ms) => is_int($ms) || is_float($ms))
                ->avg();
            $hypotheses[] = count($relatedIssues).' open/acked client slow report(s) already exist for similar paths'
                .($avgMs ? ' (avg ~'.(int) round($avgMs).' ms).' : '.');
            $fast[] = 'Open Platform → System issues / Speed and confirm whether this path is repeatedly reported.';
        }

        if ($relatedDigests !== []) {
            $top = $relatedDigests[0];
            $hypotheses[] = 'Related MySQL digest: avg '.($top['avg_sec'] ?? '?').'s / total '
                .($top['total_sec'] ?? '?').'s — '.Str::limit((string) ($top['sql'] ?? ''), 120);
            $sqlAdvice = $this->digests->suggestFixes((string) ($top['sql'] ?? ''));
            $fast = array_merge($fast, $sqlAdvice['fast_fix'] ?? []);
            $permanent = array_merge($permanent, $sqlAdvice['permanent_fix'] ?? []);
            $safeSql = array_merge($safeSql, $sqlAdvice['safe_sql'] ?? []);
            $actions = array_merge($actions, $sqlAdvice['platform_actions'] ?? []);
        }

        // Query-string smell tests
        foreach (['from', 'date_from', 'start_date', 'period_start'] as $fromKey) {
            foreach (['to', 'date_to', 'end_date', 'period_end'] as $toKey) {
                if (! empty($query[$fromKey]) && ! empty($query[$toKey])) {
                    $hypotheses[] = "Request includes a date range ({$fromKey}→{$toKey}). Wide windows are a common cause of report/list slowness.";
                    $fast[] = 'Retry with a ≤ 30-day window (ideally 14 days) and compare response time.';
                    $permanent[] = 'Ensure the endpoint always requires organization_id + a bounded created_at/date filter with a matching composite index.';
                    break 2;
                }
            }
        }

        if (isset($query['per_page']) && (int) $query['per_page'] > 100) {
            $hypotheses[] = 'per_page='.$query['per_page'].' is large — heavy payloads and DB scans.';
            $fast[] = 'Drop per_page to 25–50 and paginate.';
        }
        if (isset($query['search']) && is_string($query['search']) && str_starts_with(ltrim($query['search']), '%')) {
            $hypotheses[] = 'Leading-wildcard search often forces full table scans.';
            $fast[] = 'Use prefix search (term%) or exact codes when possible.';
        }

        if (str_contains($path, 'report') || str_contains($path, 'sales-by') || str_contains($path, 'vat') || str_contains($path, 'profit')) {
            $hypotheses[] = 'Report-style endpoints often aggregate large sales/inventory windows.';
            $fast[] = 'Narrow filters (branch, date, cashier) before exporting.';
            $permanent[] = 'Prefer pre-aggregated report tables or cached digests for heavy P&L / VAT / Sales-by-user screens.';
        }

        if (str_contains($path, 'attendance') || str_contains($path, 'hikvision')) {
            $hypotheses[] = 'Attendance / Hikvision tables grow quickly and slow list/poll endpoints.';
            $fast[] = 'Run Platform → Data retention prune, then re-test this API.';
            $actions[] = [
                'id' => 'operational_prune',
                'label' => 'Delete expired Hikvision / attendance data (operational prune)',
                'kind' => 'operational_prune',
                'body' => ['dry_run' => false, 'optimize_tables' => true],
            ];
        }

        if (str_contains($path, 'inventory') || str_contains($path, 'stock')) {
            $hypotheses[] = 'Stock endpoints may join reservations + movement history across branches.';
            $fast[] = 'Filter to one branch and confirm released reservations are pruned.';
        }

        if ($tableHints !== []) {
            foreach (array_slice($tableHints, 0, 4) as $table) {
                $safeSql[] = 'ANALYZE TABLE `'.$table.'`';
            }
            $hypotheses[] = 'Likely tables: '.implode(', ', $tableHints).'.';
        }

        if ($fast === []) {
            $fast[] = 'Capture digests on Platform → Slow queries while reproducing this API, then Ask Centrix AI on the top digest.';
            $permanent[] = 'Profile the controller with X-Response-Time / Server-Timing headers and EXPLAIN the heaviest query.';
        }

        return [
            'hypotheses' => array_values(array_unique($hypotheses)),
            'fast_fix' => array_values(array_unique($fast)),
            'permanent_fix' => array_values(array_unique($permanent)),
            'safe_sql' => array_values(array_unique($safeSql)),
            'platform_actions' => $actions,
        ];
    }

    protected function pathNeedle(string $path): string
    {
        $path = preg_replace('#^/api/v1#', '', $path) ?? $path;
        $path = trim($path, '/');
        // Drop UUID / numeric segments for broader match
        $parts = array_values(array_filter(explode('/', $path), static function ($part) {
            if ($part === '') {
                return false;
            }
            if (ctype_digit($part)) {
                return false;
            }
            if (preg_match('/^[0-9a-f-]{36}$/i', $part)) {
                return false;
            }

            return true;
        }));

        return implode('/', array_slice($parts, 0, 4));
    }
}
