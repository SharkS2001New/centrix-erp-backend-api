<?php

namespace App\Services\Platform;

use App\Models\SystemIssueReport;
use App\Services\Platform\Speed\PlatformInfraSnapshot;
use App\Services\Platform\Speed\PlatformSpeedAnalytics;
use App\Services\Platform\Speed\PlatformSpeedRouteNormalizer;
use App\Services\Platform\Speed\PlatformSpeedSyntheticRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERP Speed: live probes + aggregated traffic/DB/infra/synthetic monitoring.
 */
class PlatformSpeedService
{
    public function __construct(
        protected PlatformHealthProbe $health,
        protected SlowQueryDigestService $slowQueries,
        protected PlatformSpeedAnalytics $analytics,
        protected PlatformInfraSnapshot $infra,
        protected PlatformSpeedSyntheticRunner $synthetic,
        protected PlatformSpeedRouteNormalizer $routeNormalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $range = '1h'): array
    {
        $latencyStarted = hrtime(true);
        $latencyProbe = $this->latencyProbe();
        $serverMs = max(0, (int) round((hrtime(true) - $latencyStarted) / 1_000_000));

        $healthStarted = hrtime(true);
        $health = $this->health->run(liveQueueProbe: false);
        $healthMs = max(0, (int) round((hrtime(true) - $healthStarted) / 1_000_000));

        $slowQueries = $this->slowQueries->topQueries(5);
        $slowIssues = $this->slowIssuesSummary();
        $api = $this->analytics->apiSummary($range);
        $api['slow_endpoints'] = $this->slowEndpoints($api['slowest'] ?? []);
        $queries = $this->analytics->querySummary($range);
        $infra = $this->infra->current();
        $diagnostics = $this->analytics->diagnostics($api, $queries, $infra, $slowQueries);

        return [
            'checked_at' => now()->toIso8601String(),
            'status' => $this->overallStatus($health, $latencyProbe, $slowQueries, $slowIssues, $api),
            'server_ms' => $serverMs,
            'health_ms' => $healthMs,
            'range' => $range,
            'latency_probe' => $latencyProbe,
            'health' => $health,
            'slow_queries' => $slowQueries,
            'slow_issues' => $slowIssues,
            'api' => $api,
            'database' => [
                'app_fingerprints' => $queries,
                'mysql_digests' => $slowQueries,
            ],
            'infrastructure' => $infra,
            'queues' => $infra['queue'] ?? [],
            'diagnostics' => $diagnostics,
            'monitoring' => [
                'enabled' => (bool) config('platform_speed.enabled'),
                'record_requests' => (bool) config('platform_speed.record_requests'),
                'db_listen' => (bool) config('platform_speed.db_listen'),
                'synthetic_enabled' => (bool) config('platform_speed.synthetic.enabled'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function apiTab(string $range = '1h'): array
    {
        $api = $this->analytics->apiSummary($range);
        $api['slow_endpoints'] = $this->slowEndpoints($api['slowest'] ?? []);

        return $api;
    }

    /**
     * @param  list<array<string, mixed>>  $measuredEndpoints
     * @return list<array<string, mixed>>
     */
    protected function slowEndpoints(array $measuredEndpoints): array
    {
        $endpoints = [];
        $p95Threshold = (int) config('platform_speed.alerts.api_p95_ms', 800);

        foreach ($measuredEndpoints as $row) {
            $p95 = isset($row['p95_ms']) ? (int) $row['p95_ms'] : null;
            $max = isset($row['max_ms']) ? (int) $row['max_ms'] : 0;
            if (($p95 === null || $p95 < $p95Threshold) && $max < 5000) {
                continue;
            }

            $route = $this->routeNormalizer->normalizePath((string) ($row['route_key'] ?? ''));
            $key = strtoupper((string) ($row['method'] ?? '')).' '.$route;
            $endpoints[$key] = [
                ...$row,
                'route_key' => $route,
                'report_count' => 0,
                'reported_max_ms' => null,
                'reported_organizations' => [],
                'reports' => [],
                'source' => 'latency',
            ];
        }

        $reports = \App\Models\SystemIssueReport::query()
            ->with('organization:id,org_name,company_code')
            ->where('kind', 'slow')
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereNotNull('api_path')
            ->whereNotNull('http_method')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get([
                'id', 'organization_id', 'http_method', 'api_path', 'http_status',
                'duration_ms', 'message', 'status', 'created_at',
            ]);

        foreach ($reports as $report) {
            $method = strtoupper(trim((string) $report->http_method));
            $path = (string) (parse_url((string) $report->api_path, PHP_URL_PATH) ?: $report->api_path);
            $route = $this->routeNormalizer->normalizePath($path);
            $key = $method.' '.$route;
            if (! isset($endpoints[$key])) {
                $endpoints[$key] = [
                    'method' => $method,
                    'route_key' => $route,
                    'count' => 0,
                    'error_count' => 0,
                    'avg_ms' => null,
                    'p95_ms' => null,
                    'max_ms' => null,
                    'report_count' => 0,
                    'reported_max_ms' => null,
                    'reported_organizations' => [],
                    'reports' => [],
                    'source' => 'system_report',
                ];
            }

            $endpoint = &$endpoints[$key];
            $duration = $report->duration_ms !== null ? (int) $report->duration_ms : null;
            $endpoint['report_count']++;
            if ($duration !== null) {
                $endpoint['reported_max_ms'] = max((int) ($endpoint['reported_max_ms'] ?? 0), $duration);
            }
            if ($report->organization) {
                $org = [
                    'id' => (int) $report->organization->id,
                    'name' => (string) $report->organization->org_name,
                    'company_code' => (string) $report->organization->company_code,
                ];
                $endpoint['reported_organizations'][$org['id']] = $org;
            }
            if (count($endpoint['reports']) < 5) {
                $endpoint['reports'][] = [
                    'id' => (string) $report->id,
                    'duration_ms' => $duration,
                    'http_status' => $report->http_status !== null ? (int) $report->http_status : null,
                    'status' => (string) $report->status,
                    'message' => (string) $report->message,
                    'created_at' => $report->created_at?->toIso8601String(),
                    'organization' => $report->organization ? [
                        'id' => (int) $report->organization->id,
                        'name' => (string) $report->organization->org_name,
                        'company_code' => (string) $report->organization->company_code,
                    ] : null,
                ];
            }
            unset($endpoint);
        }

        foreach ($endpoints as &$endpoint) {
            $endpoint['reported_organizations'] = array_values($endpoint['reported_organizations']);
        }
        unset($endpoint);

        $endpoints = array_values($endpoints);
        usort($endpoints, static fn (array $a, array $b) =>
            (($b['report_count'] ?? 0) <=> ($a['report_count'] ?? 0))
            ?: (($b['reported_max_ms'] ?? $b['max_ms'] ?? 0) <=> ($a['reported_max_ms'] ?? $a['max_ms'] ?? 0))
        );

        return array_slice($endpoints, 0, 100);
    }

    /** @return array<string, mixed> */
    public function databaseTab(string $range = '1h'): array
    {
        return [
            'app_fingerprints' => $this->analytics->querySummary($range),
            'mysql_digests' => $this->slowQueries->topQueries(25),
        ];
    }

    /** @return array<string, mixed> */
    public function infrastructureTab(): array
    {
        return $this->infra->current();
    }

    /** @return array<string, mixed> */
    public function queuesTab(): array
    {
        $infra = $this->infra->current();

        return [
            'queue' => $infra['queue'] ?? [],
            'health_queue_check' => collect($infra['health']['checks'] ?? [])->firstWhere('id', 'queue'),
            'note' => 'Run live worker and infrastructure checks in ERP Speed → Infrastructure Health.',
            'health_href' => '/platform/speed',
        ];
    }

    /** @return array<string, mixed> */
    public function frontendTab(): array
    {
        return [
            'slow_issues' => $this->slowIssuesSummary(20),
            'note' => 'Browser-reported slow requests (kind=slow) when X-Response-Time shows server work ≥ 5s. Not Core Web Vitals.',
        ];
    }

    /** @return array<string, mixed> */
    public function syntheticTab(): array
    {
        $this->synthetic->ensureDefaults();
        $checks = [];
        $results = [];
        if (Schema::hasTable('platform_speed_synthetic_checks')) {
            $checks = DB::table('platform_speed_synthetic_checks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        if (Schema::hasTable('platform_speed_synthetic_results')) {
            $results = DB::table('platform_speed_synthetic_results')
                ->orderByDesc('ran_at')
                ->limit(40)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        return [
            'checks' => $checks,
            'results' => $results,
            'enabled' => (bool) config('platform_speed.synthetic.enabled'),
        ];
    }

    /** @return array<string, mixed> */
    public function diagnosticsTab(string $range = '1h'): array
    {
        $api = $this->analytics->apiSummary($range);
        $queries = $this->analytics->querySummary($range);
        $infra = $this->infra->current();
        $slowQueries = $this->slowQueries->topQueries(5);

        return [
            'items' => $this->analytics->diagnostics($api, $queries, $infra, $slowQueries),
            'monitoring' => [
                'enabled' => (bool) config('platform_speed.enabled'),
                'flush_command' => 'erp:flush-speed-metrics',
                'synthetic_command' => 'erp:run-speed-synthetic',
            ],
        ];
    }

    /**
     * Live step-by-step probe for the ERP Speed log popup (SSE).
     *
     * @param  (callable(array{event: string, phase?: string, message: string, key?: string, ms?: int|null, ok?: bool|null}): void)|null  $onProgress
     * @return array<string, mixed>
     */
    public function liveProbe(?callable $onProgress = null, string $range = '1h'): array
    {
        $emit = function (array $payload) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($payload);
            }
        };

        $emit([
            'event' => 'status',
            'phase' => 'start',
            'message' => 'Starting ERP Speed live probe…',
        ]);

        $steps = [];

        $emit(['event' => 'step', 'key' => 'db', 'phase' => 'start', 'message' => 'Probing database (SELECT 1)…']);
        $t0 = hrtime(true);
        $dbMs = null;
        $dbOk = false;
        try {
            DB::select('select 1');
            $dbMs = max(0, (int) round((hrtime(true) - $t0) / 1_000_000));
            $dbOk = true;
            $emit([
                'event' => 'step',
                'key' => 'db',
                'phase' => 'done',
                'ok' => true,
                'ms' => $dbMs,
                'message' => "Database OK — {$dbMs} ms",
            ]);
        } catch (\Throwable $e) {
            $emit([
                'event' => 'step',
                'key' => 'db',
                'phase' => 'done',
                'ok' => false,
                'ms' => null,
                'message' => 'Database failed: '.$e->getMessage(),
            ]);
        }
        $steps[] = ['key' => 'db', 'ok' => $dbOk, 'ms' => $dbMs];

        $redisSkipped = config('cache.default') !== 'redis' && config('queue.default') !== 'redis';
        $redisMs = null;
        $redisOk = $redisSkipped ? null : false;
        if ($redisSkipped) {
            $emit([
                'event' => 'step',
                'key' => 'redis',
                'phase' => 'done',
                'ok' => null,
                'message' => 'Redis skipped (not the default cache/queue store)',
            ]);
        } else {
            $emit(['event' => 'step', 'key' => 'redis', 'phase' => 'start', 'message' => 'Probing Redis…']);
            try {
                $t0 = hrtime(true);
                Cache::store('redis')->put('platform:speed:ping', '1', 5);
                $ok = Cache::store('redis')->get('platform:speed:ping') === '1';
                $redisMs = $ok ? max(0, (int) round((hrtime(true) - $t0) / 1_000_000)) : null;
                $redisOk = $ok;
                $emit([
                    'event' => 'step',
                    'key' => 'redis',
                    'phase' => 'done',
                    'ok' => $ok,
                    'ms' => $redisMs,
                    'message' => $ok ? "Redis OK — {$redisMs} ms" : 'Redis probe returned unexpected value',
                ]);
            } catch (\Throwable $e) {
                $emit([
                    'event' => 'step',
                    'key' => 'redis',
                    'phase' => 'done',
                    'ok' => false,
                    'message' => 'Redis failed: '.$e->getMessage(),
                ]);
            }
        }
        $steps[] = ['key' => 'redis', 'ok' => $redisOk, 'ms' => $redisMs, 'skipped' => $redisSkipped];

        $emit(['event' => 'step', 'key' => 'health', 'phase' => 'start', 'message' => 'Running infrastructure health checks…']);
        $t0 = hrtime(true);
        $health = $this->health->run(liveQueueProbe: false);
        $healthMs = max(0, (int) round((hrtime(true) - $t0) / 1_000_000));
        foreach ($health['checks'] ?? [] as $check) {
            $ok = $check['ok'] ?? null;
            $label = (string) ($check['label'] ?? $check['id'] ?? 'check');
            $detail = (string) ($check['detail'] ?? '');
            $tone = $ok === false ? 'failed' : ($ok === null ? 'skipped' : 'OK');
            $emit([
                'event' => 'step',
                'key' => 'health_'.($check['id'] ?? 'x'),
                'phase' => 'done',
                'ok' => $ok,
                'message' => "{$label}: {$tone}".($detail !== '' ? " — {$detail}" : ''),
            ]);
        }
        $emit([
            'event' => 'step',
            'key' => 'health',
            'phase' => 'done',
            'ok' => (bool) ($health['ok'] ?? false),
            'ms' => $healthMs,
            'message' => 'Infrastructure checks finished in '.$healthMs.' ms',
        ]);

        $emit(['event' => 'step', 'key' => 'slow_queries', 'phase' => 'start', 'message' => 'Reading MySQL slow digests…']);
        $slowQueries = $this->slowQueries->topQueries(5);
        $digestCount = count($slowQueries['queries'] ?? []);
        $topAvg = 0.0;
        foreach ($slowQueries['queries'] ?? [] as $i => $query) {
            $avg = (float) ($query['avg_sec'] ?? 0);
            $topAvg = max($topAvg, $avg);
            $sql = (string) ($query['sql'] ?? '');
            $short = strlen($sql) > 120 ? substr($sql, 0, 117).'…' : $sql;
            $emit([
                'event' => 'step',
                'key' => 'slow_q_'.$i,
                'phase' => 'done',
                'ok' => $avg < 1.0,
                'message' => sprintf('Digest #%d · avg %.2fs · %s', $i + 1, $avg, $short),
            ]);
        }
        $emit([
            'event' => 'step',
            'key' => 'slow_queries',
            'phase' => 'done',
            'ok' => $topAvg < 1.0,
            'message' => $digestCount === 0
                ? 'No slow query digests available'
                : "Top digest avg {$topAvg}s across {$digestCount} sample(s)",
        ]);

        $emit(['event' => 'step', 'key' => 'api', 'phase' => 'start', 'message' => "Loading API aggregates ({$range})…"]);
        $api = $this->analytics->apiSummary($range);
        $emit([
            'event' => 'step',
            'key' => 'api',
            'phase' => 'done',
            'ok' => true,
            'message' => sprintf(
                'API p50=%sms p95=%sms · %s requests',
                $api['p50'] ?? '—',
                $api['p95'] ?? '—',
                $api['request_count'] ?? 0,
            ),
        ]);

        $latencyProbe = [
            'db_ms' => $dbMs,
            'redis_ms' => $redisMs,
            'redis_skipped' => $redisSkipped,
        ];
        $slowIssues = $this->slowIssuesSummary();
        $status = $this->overallStatus($health, $latencyProbe, $slowQueries, $slowIssues, $api);

        $summary = [
            'status' => $status,
            'latency_probe' => $latencyProbe,
            'health' => $health,
            'slow_queries' => $slowQueries,
            'slow_issues' => $slowIssues,
            'api' => [
                'p50' => $api['p50'] ?? null,
                'p95' => $api['p95'] ?? null,
                'p99' => $api['p99'] ?? null,
                'request_count' => $api['request_count'] ?? 0,
                'error_rate_percent' => $api['error_rate_percent'] ?? null,
            ],
            'steps' => $steps,
            'checked_at' => now()->toIso8601String(),
            'range' => $range,
        ];

        $emit([
            'event' => 'status',
            'phase' => 'done',
            'message' => 'Live probe complete — overall status: '.strtoupper($status),
        ]);

        return $summary;
    }

    /**
     * @return array{db_ms: int|null, redis_ms: int|null, redis_skipped: bool}
     */
    protected function latencyProbe(): array
    {
        $dbMs = null;
        try {
            $t0 = hrtime(true);
            DB::select('select 1');
            $dbMs = max(0, (int) round((hrtime(true) - $t0) / 1_000_000));
        } catch (\Throwable) {
            $dbMs = null;
        }

        $redisSkipped = config('cache.default') !== 'redis' && config('queue.default') !== 'redis';
        $redisMs = null;
        if (! $redisSkipped) {
            try {
                $t0 = hrtime(true);
                Cache::store('redis')->put('platform:speed:ping', '1', 5);
                $ok = Cache::store('redis')->get('platform:speed:ping') === '1';
                $redisMs = $ok
                    ? max(0, (int) round((hrtime(true) - $t0) / 1_000_000))
                    : null;
            } catch (\Throwable) {
                $redisMs = null;
            }
        }

        return [
            'db_ms' => $dbMs,
            'redis_ms' => $redisMs,
            'redis_skipped' => $redisSkipped,
        ];
    }

    /**
     * @return array{open: int, acknowledged: int, active: int, recent: list<array<string, mixed>>}
     */
    protected function slowIssuesSummary(int $limit = 5): array
    {
        $open = SystemIssueReport::query()
            ->where('kind', 'slow')
            ->where('status', 'open')
            ->count();
        $acknowledged = SystemIssueReport::query()
            ->where('kind', 'slow')
            ->where('status', 'acknowledged')
            ->count();

        $recent = SystemIssueReport::query()
            ->with(['organization:id,org_name,company_code'])
            ->where('kind', 'slow')
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static function (SystemIssueReport $row) {
                return [
                    'id' => $row->id,
                    'api_path' => $row->api_path,
                    'http_method' => $row->http_method,
                    'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                    'status' => $row->status,
                    'message' => $row->message,
                    'organization' => $row->organization
                        ? [
                            'id' => $row->organization->id,
                            'org_name' => $row->organization->org_name,
                            'company_code' => $row->organization->company_code,
                        ]
                        : null,
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'open' => $open,
            'acknowledged' => $acknowledged,
            'active' => $open + $acknowledged,
            'recent' => $recent,
        ];
    }

    /**
     * @param  array<string, mixed>  $health
     * @param  array{db_ms: int|null, redis_ms: int|null, redis_skipped: bool}  $latencyProbe
     * @param  array<string, mixed>  $slowQueries
     * @param  array{open: int, acknowledged: int, active: int, recent: list<array<string, mixed>>}  $slowIssues
     * @param  array<string, mixed>  $api
     * @return 'ok'|'degraded'|'critical'
     */
    protected function overallStatus(array $health, array $latencyProbe, array $slowQueries, array $slowIssues, array $api = []): string
    {
        $failedChecks = collect($health['checks'] ?? [])
            ->filter(static fn (array $check) => ($check['ok'] ?? null) === false)
            ->count();

        if ($failedChecks > 0 || $latencyProbe['db_ms'] === null) {
            return 'critical';
        }

        $topAvg = 0.0;
        foreach ($slowQueries['queries'] ?? [] as $query) {
            $topAvg = max($topAvg, (float) ($query['avg_sec'] ?? 0));
        }

        $dbSlow = ($latencyProbe['db_ms'] ?? 0) >= 200;
        $redisSlow = ! $latencyProbe['redis_skipped']
            && $latencyProbe['redis_ms'] !== null
            && $latencyProbe['redis_ms'] >= 100;
        $hasSlowQueries = $topAvg >= 1.0;
        $hasSlowIssues = ($slowIssues['active'] ?? 0) > 0;
        $apiDegraded = ($api['p95'] ?? 0) >= (int) config('platform_speed.alerts.api_p95_ms', 800)
            && ($api['request_count'] ?? 0) > 10;

        if ($dbSlow || $redisSlow || $hasSlowQueries || $hasSlowIssues || $apiDegraded) {
            return 'degraded';
        }

        return 'ok';
    }
}
