<?php

namespace App\Services\Platform\Speed;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class PlatformSpeedSyntheticRunner
{
    /**
     * @return array{ran: int, failed: int}
     */
    public function runDue(): array
    {
        if (! config('platform_speed.synthetic.enabled') || ! Schema::hasTable('platform_speed_synthetic_checks')) {
            return ['ran' => 0, 'failed' => 0];
        }

        $this->ensureDefaults();

        $ran = 0;
        $failed = 0;
        $checks = DB::table('platform_speed_synthetic_checks')->where('is_active', true)->get();
        foreach ($checks as $check) {
            $interval = max(30, (int) $check->interval_seconds);
            if ($check->last_run_at && now()->diffInSeconds($check->last_run_at) < $interval) {
                continue;
            }
            $ok = $this->runOne($check);
            $ran++;
            if (! $ok) {
                $failed++;
            }
        }

        return ['ran' => $ran, 'failed' => $failed];
    }

    public function ensureDefaults(): void
    {
        if (! Schema::hasTable('platform_speed_synthetic_checks')) {
            return;
        }
        if (DB::table('platform_speed_synthetic_checks')->exists()) {
            return;
        }
        $now = now();
        DB::table('platform_speed_synthetic_checks')->insert([
            [
                'name' => 'API health',
                'method' => 'GET',
                'path' => '/api/v1/health',
                'interval_seconds' => 60,
                'timeout_ms' => 5000,
                'expect_status' => 200,
                'auth_mode' => 'none',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Framework up',
                'method' => 'GET',
                'path' => '/up',
                'interval_seconds' => 60,
                'timeout_ms' => 3000,
                'expect_status' => 200,
                'auth_mode' => 'none',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    protected function runOne(object $check): bool
    {
        $base = rtrim((string) config('platform_speed.synthetic.base_url'), '/');
        $path = (string) $check->path;
        $url = str_starts_with($path, 'http') ? $path : $base.$path;
        $timeoutSec = max(1, (int) ceil(((int) $check->timeout_ms) / 1000));
        $headers = [
            'Accept' => 'application/json',
            'X-Platform-Speed-Synthetic' => '1',
            'User-Agent' => 'Platform-Speed-Synthetic/1.0',
        ];
        if ($check->auth_mode === 'token') {
            $token = (string) config('platform_speed.synthetic.token', '');
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer '.$token;
            }
        }

        $ok = false;
        $status = null;
        $totalMs = null;
        $error = null;
        $t0 = hrtime(true);
        try {
            $response = Http::timeout($timeoutSec)
                ->withHeaders($headers)
                ->send(strtoupper((string) $check->method), $url);
            $totalMs = max(0, (int) round((hrtime(true) - $t0) / 1_000_000));
            $status = $response->status();
            $ok = $status === (int) $check->expect_status;
            if (! $ok) {
                $error = 'Unexpected status '.$status;
            }
        } catch (\Throwable $e) {
            $totalMs = max(0, (int) round((hrtime(true) - $t0) / 1_000_000));
            $error = substr($e->getMessage(), 0, 500);
            $ok = false;
        }

        DB::table('platform_speed_synthetic_results')->insert([
            'check_id' => $check->id,
            'ran_at' => now(),
            'ok' => $ok,
            'status_code' => $status,
            'total_ms' => $totalMs,
            'error' => $error,
        ]);
        DB::table('platform_speed_synthetic_checks')->where('id', $check->id)->update([
            'last_run_at' => now(),
            'updated_at' => now(),
        ]);

        return $ok;
    }
}
