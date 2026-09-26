<?php

namespace App\Services\Platform;

use App\Models\SystemIssueReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates infra health, DB/Redis micro-timings, slow MySQL digests, and
 * client-reported slow requests for the platform ERP Speed dashboard.
 */
class PlatformSpeedService
{
    public function __construct(
        protected PlatformHealthProbe $health,
        protected SlowQueryDigestService $slowQueries,
    ) {}

    /**
     * @return array{
     *   checked_at: string,
     *   status: 'ok'|'degraded'|'critical',
     *   server_ms: int,
     *   latency_probe: array{db_ms: int|null, redis_ms: int|null, redis_skipped: bool},
     *   health: array<string, mixed>,
     *   slow_queries: array<string, mixed>,
     *   slow_issues: array{open: int, acknowledged: int, active: int, recent: list<array<string, mixed>>}
     * }
     */
    public function snapshot(): array
    {
        $started = hrtime(true);

        $latencyProbe = $this->latencyProbe();
        $health = $this->health->run();
        $slowQueries = $this->slowQueries->topQueries(5);
        $slowIssues = $this->slowIssuesSummary();

        $serverMs = max(0, (int) round((hrtime(true) - $started) / 1_000_000));

        return [
            'checked_at' => now()->toIso8601String(),
            'status' => $this->overallStatus($health, $latencyProbe, $slowQueries, $slowIssues),
            'server_ms' => $serverMs,
            'latency_probe' => $latencyProbe,
            'health' => $health,
            'slow_queries' => $slowQueries,
            'slow_issues' => $slowIssues,
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
    protected function slowIssuesSummary(): array
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
            ->limit(5)
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
     * @return 'ok'|'degraded'|'critical'
     */
    protected function overallStatus(array $health, array $latencyProbe, array $slowQueries, array $slowIssues): string
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

        if ($dbSlow || $redisSlow || $hasSlowQueries || $hasSlowIssues) {
            return 'degraded';
        }

        return 'ok';
    }
}
