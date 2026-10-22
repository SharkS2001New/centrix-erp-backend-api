<?php

namespace App\Services\Platform\Speed;

use App\Services\Platform\PlatformHealthProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Collect only metrics PHP can observe. Unavailable metrics are labeled explicitly.
 */
class PlatformInfraSnapshot
{
    public function __construct(
        protected PlatformHealthProbe $health,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $memory = [
            'available' => true,
            'php_memory_mb' => (int) round(memory_get_usage(true) / 1048576),
            'php_peak_memory_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
            'note' => 'Process memory for this PHP worker — not host RAM.',
        ];

        $load = ['available' => false, 'load_1' => null, 'note' => 'sys_getloadavg() unavailable on this host.'];
        if (function_exists('sys_getloadavg')) {
            $avg = @sys_getloadavg();
            if (is_array($avg) && isset($avg[0])) {
                $load = [
                    'available' => true,
                    'load_1' => round((float) $avg[0], 2),
                    'load_5' => isset($avg[1]) ? round((float) $avg[1], 2) : null,
                    'load_15' => isset($avg[2]) ? round((float) $avg[2], 2) : null,
                    'note' => 'OS load average (not CPU %).',
                ];
            }
        }

        $disk = ['available' => false, 'used_percent' => null, 'note' => 'disk_free_space unavailable.'];
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free !== false && $total !== false && $total > 0) {
            $used = max(0, $total - $free);
            $disk = [
                'available' => true,
                'used_percent' => round(($used / $total) * 100, 1),
                'free_gb' => round($free / 1073741824, 2),
                'total_gb' => round($total / 1073741824, 2),
                'path' => $path,
                'note' => null,
            ];
        }

        $cpu = [
            'available' => false,
            'percent' => null,
            'note' => 'Host CPU % needs a node exporter or Kubernetes metrics agent.',
        ];

        return [
            'checked_at' => now()->toIso8601String(),
            'cpu' => $cpu,
            'memory' => $memory,
            'load' => $load,
            'disk' => $disk,
            'redis' => $this->redisStats(),
            'mysql' => $this->mysqlStats(),
            'queue' => $this->queueStats(),
            'health' => $this->health->run(liveQueueProbe: false),
        ];
    }

    public function sampleToDatabase(): void
    {
        if (! Schema::hasTable('platform_speed_infra_minutes')) {
            return;
        }
        $snap = $this->current();
        $minute = now()->format('Y-m-d H:i:00');
        $payload = [
            'php_memory_mb' => $snap['memory']['php_memory_mb'] ?? null,
            'php_peak_memory_mb' => $snap['memory']['php_peak_memory_mb'] ?? null,
            'load_1' => $snap['load']['available'] ? ($snap['load']['load_1'] ?? null) : null,
            'disk_used_percent' => $snap['disk']['available'] ? ($snap['disk']['used_percent'] ?? null) : null,
            'redis_memory_mb' => $snap['redis']['available'] ? ($snap['redis']['used_memory_mb'] ?? null) : null,
            'redis_ping_ms' => $snap['redis']['available'] ? ($snap['redis']['ping_ms'] ?? null) : null,
            'mysql_threads_connected' => $snap['mysql']['available'] ? ($snap['mysql']['threads_connected'] ?? null) : null,
            'queue_pending' => $snap['queue']['pending'] ?? null,
            'queue_failed' => $snap['queue']['failed'] ?? null,
            'meta' => json_encode([
                'cpu' => $snap['cpu'],
                'load_note' => $snap['load']['note'] ?? null,
            ]),
        ];

        $existing = DB::table('platform_speed_infra_minutes')->where('minute_at', $minute)->first();
        if ($existing) {
            DB::table('platform_speed_infra_minutes')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('platform_speed_infra_minutes')->insert(array_merge($payload, ['minute_at' => $minute]));
        }
    }

    /** @return array<string, mixed> */
    protected function redisStats(): array
    {
        if (config('cache.default') !== 'redis') {
            return [
                'available' => false,
                'note' => 'Cache driver is not redis.',
            ];
        }
        try {
            $t0 = hrtime(true);
            Cache::store('redis')->put('platform:speed:infra', '1', 5);
            $ok = Cache::store('redis')->get('platform:speed:infra') === '1';
            $pingMs = max(0, (int) round((hrtime(true) - $t0) / 1_000_000));
            $usedMb = null;
            try {
                $info = Redis::connection()->info('memory');
                if (is_array($info)) {
                    $bytes = (int) ($info['used_memory'] ?? $info['Used_memory'] ?? 0);
                    if ($bytes > 0) {
                        $usedMb = (int) round($bytes / 1048576);
                    }
                }
            } catch (\Throwable) {
                //
            }

            return [
                'available' => $ok,
                'ping_ms' => $pingMs,
                'used_memory_mb' => $usedMb,
                'note' => $ok ? null : 'Redis ping failed.',
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'note' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    protected function mysqlStats(): array
    {
        try {
            $t0 = hrtime(true);
            DB::select('select 1');
            $pingMs = max(0, (int) round((hrtime(true) - $t0) / 1_000_000));
            $threads = null;
            try {
                $row = DB::selectOne("show status where Variable_name = 'Threads_connected'");
                $threads = isset($row->Value) ? (int) $row->Value : null;
            } catch (\Throwable) {
                //
            }

            return [
                'available' => true,
                'ping_ms' => $pingMs,
                'threads_connected' => $threads,
                'note' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'note' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    protected function queueStats(): array
    {
        $driver = (string) config('queue.default', 'sync');
        if ($driver === 'sync') {
            return [
                'available' => true,
                'driver' => $driver,
                'pending' => 0,
                'failed' => 0,
                'note' => 'Sync queue — no backlog.',
            ];
        }
        $pending = null;
        $failed = null;
        try {
            if (Schema::hasTable('jobs')) {
                $pending = (int) DB::table('jobs')->count();
            }
            if (Schema::hasTable('failed_jobs')) {
                $failed = (int) DB::table('failed_jobs')->count();
            }
        } catch (\Throwable) {
            //
        }

        return [
            'available' => $pending !== null,
            'driver' => $driver,
            'pending' => $pending,
            'failed' => $failed,
            'note' => $pending === null
                ? 'Queue tables not readable. Live worker probe is on Infrastructure Health.'
                : null,
        ];
    }
}
