<?php

namespace App\Services\Platform;

use App\Models\SystemIssueReport;
use App\Services\Platform\Speed\PlatformInfraSnapshot;
use App\Services\Platform\Speed\PlatformSpeedAnalytics;
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
        return $this->analytics->apiSummary($range);
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
            'note' => 'Live worker probe runs on Infrastructure Health to keep Speed Measure snappy.',
            'health_href' => '/platform/health',
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
