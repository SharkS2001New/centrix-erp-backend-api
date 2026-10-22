<?php

namespace App\Services\Platform\Speed;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformSpeedMetricsFlusher
{
    public function __construct(
        protected PlatformSpeedMetricsRecorder $recorder,
        protected PlatformSpeedHistogram $histogram,
    ) {}

    /**
     * @return array{api: int, queries: int}
     */
    public function flush(): array
    {
        $api = 0;
        $queries = 0;

        if (Schema::hasTable('platform_speed_api_minutes')) {
            foreach ($this->recorder->pendingApiKeys() as $key) {
                $row = $this->recorder->readHash($key);
                if (! is_array($row) || empty($row['minute_at']) || empty($row['route_key'])) {
                    $this->recorder->forgetKey($key, $this->recorder->apiIndexKey());
                    continue;
                }
                $this->upsertApi($row);
                $this->recorder->forgetKey($key, $this->recorder->apiIndexKey());
                $api++;
            }
        }

        if (Schema::hasTable('platform_speed_query_minutes')) {
            foreach ($this->recorder->pendingQueryKeys() as $key) {
                $row = $this->recorder->readHash($key);
                if (! is_array($row) || empty($row['minute_at']) || empty($row['fingerprint'])) {
                    $this->recorder->forgetKey($key, $this->recorder->queryIndexKey());
                    continue;
                }
                $this->upsertQuery($row);
                $this->recorder->forgetKey($key, $this->recorder->queryIndexKey());
                $queries++;
            }
        }

        return ['api' => $api, 'queries' => $queries];
    }

    public function prune(): int
    {
        $days = max(1, (int) config('platform_speed.retention_days', 14));
        $cutoff = now()->subDays($days);
        $deleted = 0;
        foreach (['platform_speed_api_minutes', 'platform_speed_query_minutes', 'platform_speed_infra_minutes'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $deleted += DB::table($table)->where('minute_at', '<', $cutoff)->delete();
        }
        if (Schema::hasTable('platform_speed_synthetic_results')) {
            $deleted += DB::table('platform_speed_synthetic_results')
                ->where('ran_at', '<', $cutoff)
                ->delete();
        }

        return $deleted;
    }

    /** @param  array<string, mixed>  $row */
    protected function upsertApi(array $row): void
    {
        $minute = (string) $row['minute_at'];
        $method = strtoupper((string) ($row['method'] ?? 'GET'));
        $route = (string) $row['route_key'];
        $histogram = $this->histogramFromRow($row);

        $existing = DB::table('platform_speed_api_minutes')
            ->where('minute_at', $minute)
            ->where('method', $method)
            ->where('route_key', $route)
            ->first();

        $count = (int) ($row['count'] ?? 0);
        $payload = [
            'count' => $count + (int) ($existing->count ?? 0),
            'error_count' => (int) ($row['error_count'] ?? 0) + (int) ($existing->error_count ?? 0),
            'timeout_count' => (int) ($row['timeout_count'] ?? 0) + (int) ($existing->timeout_count ?? 0),
            'total_ms' => (int) ($row['total_ms'] ?? 0) + (int) ($existing->total_ms ?? 0),
            'max_ms' => max((int) ($row['max_ms'] ?? 0), (int) ($existing->max_ms ?? 0)),
            'total_bytes' => (int) ($row['total_bytes'] ?? 0) + (int) ($existing->total_bytes ?? 0),
            'histogram' => json_encode(
                $this->histogram->merge(
                    is_array($existing?->histogram) ? (array) json_decode((string) $existing->histogram, true) : (
                        is_string($existing?->histogram ?? null) ? (array) json_decode((string) $existing->histogram, true) : $this->histogram->emptyBuckets()
                    ),
                    $histogram,
                ),
            ),
        ];

        if ($existing) {
            DB::table('platform_speed_api_minutes')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('platform_speed_api_minutes')->insert(array_merge($payload, [
                'minute_at' => $minute,
                'method' => $method,
                'route_key' => $route,
            ]));
        }
    }

    /** @param  array<string, mixed>  $row */
    protected function upsertQuery(array $row): void
    {
        $minute = (string) $row['minute_at'];
        $fp = (string) $row['fingerprint'];
        $existing = DB::table('platform_speed_query_minutes')
            ->where('minute_at', $minute)
            ->where('fingerprint', $fp)
            ->first();

        $payload = [
            'sql_preview' => substr((string) ($row['sql_preview'] ?? ''), 0, 500),
            'count' => (int) ($row['count'] ?? 0) + (int) ($existing->count ?? 0),
            'slow_count' => (int) ($row['slow_count'] ?? 0) + (int) ($existing->slow_count ?? 0),
            'total_ms' => (int) ($row['total_ms'] ?? 0) + (int) ($existing->total_ms ?? 0),
            'max_ms' => max((int) ($row['max_ms'] ?? 0), (int) ($existing->max_ms ?? 0)),
            'n_plus_one_hits' => (int) ($row['n_plus_one_hits'] ?? 0) + (int) ($existing->n_plus_one_hits ?? 0),
        ];

        if ($existing) {
            DB::table('platform_speed_query_minutes')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('platform_speed_query_minutes')->insert(array_merge($payload, [
                'minute_at' => $minute,
                'fingerprint' => $fp,
            ]));
        }
    }

    /** @param  array<string, mixed>  $row */
    protected function histogramFromRow(array $row): array
    {
        if (isset($row['histogram']) && is_array($row['histogram'])) {
            return $row['histogram'];
        }
        if (isset($row['histogram']) && is_string($row['histogram'])) {
            $decoded = json_decode($row['histogram'], true);

            return is_array($decoded) ? $decoded : $this->histogram->emptyBuckets();
        }

        $buckets = $this->histogram->emptyBuckets();
        foreach ($row as $field => $value) {
            if (! is_string($field) || ! str_starts_with($field, 'h:')) {
                continue;
            }
            $bucket = substr($field, 2);
            if (array_key_exists($bucket, $buckets)) {
                $buckets[$bucket] = (int) $value;
            }
        }

        return $buckets;
    }
}
