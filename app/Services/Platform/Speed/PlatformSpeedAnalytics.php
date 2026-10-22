<?php

namespace App\Services\Platform\Speed;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformSpeedAnalytics
{
    public function __construct(
        protected PlatformSpeedHistogram $histogram,
    ) {}

    public function rangeStart(string $range): \Carbon\Carbon
    {
        return match ($range) {
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            default => now()->subHour(),
        };
    }

    /**
     * @return array{
     *   range: string,
     *   from: string,
     *   to: string,
     *   request_count: int,
     *   error_count: int,
     *   throughput_per_min: float,
     *   avg_ms: float|null,
     *   max_ms: int,
     *   p50: int|null,
     *   p95: int|null,
     *   p99: int|null,
     *   error_rate_percent: float,
     *   trend: list<array{t: string, p50: int|null, p95: int|null, p99: int|null, count: int}>,
     *   slowest: list<array<string, mixed>>,
     *   monitoring_active: bool
     * }
     */
    public function apiSummary(string $range = '1h'): array
    {
        $from = $this->rangeStart($range);
        $to = now();
        $empty = [
            'range' => $range,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'request_count' => 0,
            'error_count' => 0,
            'throughput_per_min' => 0.0,
            'avg_ms' => null,
            'max_ms' => 0,
            'p50' => null,
            'p95' => null,
            'p99' => null,
            'error_rate_percent' => 0.0,
            'trend' => [],
            'slowest' => [],
            'monitoring_active' => (bool) config('platform_speed.enabled'),
        ];

        if (! Schema::hasTable('platform_speed_api_minutes')) {
            return $empty;
        }

        $rows = DB::table('platform_speed_api_minutes')
            ->where('minute_at', '>=', $from)
            ->where('minute_at', '<=', $to)
            ->get();

        if ($rows->isEmpty()) {
            return $empty;
        }

        $count = 0;
        $errors = 0;
        $totalMs = 0;
        $maxMs = 0;
        $hist = $this->histogram->emptyBuckets();
        $byMinute = [];
        $byRoute = [];

        foreach ($rows as $row) {
            $c = (int) $row->count;
            $count += $c;
            $errors += (int) $row->error_count;
            $totalMs += (int) $row->total_ms;
            $maxMs = max($maxMs, (int) $row->max_ms);
            $bucket = json_decode((string) $row->histogram, true);
            if (is_array($bucket)) {
                $hist = $this->histogram->merge($hist, $bucket);
            }
            $minute = (string) $row->minute_at;
            if (! isset($byMinute[$minute])) {
                $byMinute[$minute] = ['count' => 0, 'hist' => $this->histogram->emptyBuckets()];
            }
            $byMinute[$minute]['count'] += $c;
            if (is_array($bucket)) {
                $byMinute[$minute]['hist'] = $this->histogram->merge($byMinute[$minute]['hist'], $bucket);
            }
            $rk = $row->method.' '.$row->route_key;
            if (! isset($byRoute[$rk])) {
                $byRoute[$rk] = [
                    'method' => $row->method,
                    'route_key' => $row->route_key,
                    'count' => 0,
                    'error_count' => 0,
                    'total_ms' => 0,
                    'max_ms' => 0,
                    'hist' => $this->histogram->emptyBuckets(),
                ];
            }
            $byRoute[$rk]['count'] += $c;
            $byRoute[$rk]['error_count'] += (int) $row->error_count;
            $byRoute[$rk]['total_ms'] += (int) $row->total_ms;
            $byRoute[$rk]['max_ms'] = max($byRoute[$rk]['max_ms'], (int) $row->max_ms);
            if (is_array($bucket)) {
                $byRoute[$rk]['hist'] = $this->histogram->merge($byRoute[$rk]['hist'], $bucket);
            }
        }

        $pct = $this->histogram->percentiles($hist, $count);
        $minutes = max(1, (int) ceil($from->diffInSeconds($to) / 60));
        ksort($byMinute);
        $trend = [];
        foreach ($byMinute as $t => $data) {
            $p = $this->histogram->percentiles($data['hist'], (int) $data['count']);
            $trend[] = [
                't' => $t,
                'p50' => $p['p50'],
                'p95' => $p['p95'],
                'p99' => $p['p99'],
                'count' => (int) $data['count'],
            ];
        }

        $slowest = [];
        foreach ($byRoute as $route) {
            $p = $this->histogram->percentiles($route['hist'], (int) $route['count']);
            $slowest[] = [
                'method' => $route['method'],
                'route_key' => $route['route_key'],
                'count' => $route['count'],
                'error_count' => $route['error_count'],
                'avg_ms' => $route['count'] > 0 ? (int) round($route['total_ms'] / $route['count']) : null,
                'p95_ms' => $p['p95'],
                'max_ms' => $route['max_ms'],
            ];
        }
        usort($slowest, fn ($a, $b) => ($b['p95_ms'] ?? 0) <=> ($a['p95_ms'] ?? 0));

        return [
            'range' => $range,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'request_count' => $count,
            'error_count' => $errors,
            'throughput_per_min' => round($count / $minutes, 1),
            'avg_ms' => $count > 0 ? round($totalMs / $count, 1) : null,
            'max_ms' => $maxMs,
            'p50' => $pct['p50'],
            'p95' => $pct['p95'],
            'p99' => $pct['p99'],
            'error_rate_percent' => $count > 0 ? round(($errors / $count) * 100, 2) : 0.0,
            'trend' => array_slice($trend, -60),
            'slowest' => array_slice($slowest, 0, 25),
            'monitoring_active' => (bool) config('platform_speed.enabled'),
        ];
    }

    /**
     * @return array{range: string, fingerprints: list<array<string, mixed>>, query_latency_ms: float|null}
     */
    public function querySummary(string $range = '1h'): array
    {
        $from = $this->rangeStart($range);
        if (! Schema::hasTable('platform_speed_query_minutes')) {
            return ['range' => $range, 'fingerprints' => [], 'query_latency_ms' => null];
        }
        $rows = DB::table('platform_speed_query_minutes')
            ->where('minute_at', '>=', $from)
            ->selectRaw('fingerprint, MAX(sql_preview) as sql_preview, SUM(count) as count, SUM(slow_count) as slow_count, SUM(total_ms) as total_ms, MAX(max_ms) as max_ms, SUM(n_plus_one_hits) as n_plus_one_hits')
            ->groupBy('fingerprint')
            ->orderByDesc('slow_count')
            ->limit(40)
            ->get();

        $totalMs = 0;
        $totalCount = 0;
        $fingerprints = [];
        foreach ($rows as $row) {
            $c = (int) $row->count;
            $totalCount += $c;
            $totalMs += (int) $row->total_ms;
            $fingerprints[] = [
                'fingerprint' => $row->fingerprint,
                'sql_preview' => $row->sql_preview,
                'count' => $c,
                'slow_count' => (int) $row->slow_count,
                'avg_ms' => $c > 0 ? (int) round(((int) $row->total_ms) / $c) : null,
                'max_ms' => (int) $row->max_ms,
                'n_plus_one_hits' => (int) $row->n_plus_one_hits,
            ];
        }

        return [
            'range' => $range,
            'fingerprints' => $fingerprints,
            'query_latency_ms' => $totalCount > 0 ? round($totalMs / $totalCount, 1) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function diagnostics(array $api, array $queries, array $infra, array $slowQueries): array
    {
        $out = [];
        $p95Alert = (int) config('platform_speed.alerts.api_p95_ms', 800);
        if (($api['p95'] ?? null) !== null && $api['p95'] >= $p95Alert) {
            $out[] = [
                'severity' => 'warning',
                'title' => 'API p95 above threshold',
                'detail' => 'p95 is '.$api['p95'].' ms (threshold '.$p95Alert.' ms). Check slowest endpoints.',
                'href' => '/platform/speed?tab=api',
            ];
        }
        $errAlert = (float) config('platform_speed.alerts.error_rate_percent', 2);
        if (($api['error_rate_percent'] ?? 0) >= $errAlert && ($api['request_count'] ?? 0) > 20) {
            $out[] = [
                'severity' => 'error',
                'title' => 'Elevated API error rate',
                'detail' => $api['error_rate_percent'].'% of requests returned 5xx.',
                'href' => '/platform/speed?tab=api',
            ];
        }
        $nPlus = collect($queries['fingerprints'] ?? [])->first(fn ($r) => ($r['n_plus_one_hits'] ?? 0) > 0);
        if ($nPlus) {
            $out[] = [
                'severity' => 'warning',
                'title' => 'Possible N+1 query pattern',
                'detail' => substr((string) $nPlus['sql_preview'], 0, 120),
                'href' => '/platform/speed?tab=database',
            ];
        }
        $digestAvg = (float) ($slowQueries['queries'][0]['avg_sec'] ?? 0);
        if ($digestAvg >= (float) config('platform_speed.alerts.digest_avg_sec', 1)) {
            $out[] = [
                'severity' => 'warning',
                'title' => 'Slow MySQL digest detected',
                'detail' => 'Top digest avg '.$digestAvg.'s. Review the digest on Platform Speed → Database.',
                'href' => '/platform/speed?tab=database',
            ];
        }
        $pending = $infra['queue']['pending'] ?? null;
        if (is_int($pending) && $pending >= (int) config('platform_speed.alerts.queue_backlog', 100)) {
            $out[] = [
                'severity' => 'warning',
                'title' => 'Queue backlog high',
                'detail' => $pending.' pending jobs. Check queue workers on Infrastructure Health.',
                'href' => '/platform/health',
            ];
        }
        if (! ($api['monitoring_active'] ?? true)) {
            $out[] = [
                'severity' => 'info',
                'title' => 'Request recording disabled',
                'detail' => 'Set PLATFORM_SPEED_ENABLED=true and ensure erp:flush-speed-metrics runs every minute.',
                'href' => '/platform/speed?tab=diagnostics',
            ];
        }
        if (($api['request_count'] ?? 0) === 0) {
            $out[] = [
                'severity' => 'info',
                'title' => 'No traffic aggregates yet',
                'detail' => 'Generate API traffic, then wait for the next flush (every minute). Probe KPIs still work via Measure.',
                'href' => '/platform/speed',
            ];
        }

        return $out;
    }
}
