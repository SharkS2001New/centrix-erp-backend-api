<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Lightweight infrastructure probes for platform super-admins.
 */
class PlatformHealthProbe
{
    public const SCHEDULER_HEARTBEAT_KEY = 'platform:scheduler:heartbeat';

    public const QUEUE_PROBE_KEY = 'platform:queue:probe';

    /**
     * @return array{
     *   ok: bool,
     *   checked_at: string,
     *   hostname: string,
     *   checks: list<array{id: string, label: string, ok: bool|null, detail: string}>
     * }
     */
    public function run(): array
    {
        $checks = [
            $this->checkApp(),
            $this->checkDatabase(),
            $this->checkRedis(),
            $this->checkQueue(),
            $this->checkScheduler(),
            $this->checkReverb(),
            ...$this->checkPeerPods(),
        ];

        $ok = collect($checks)->every(
            static fn (array $check) => $check['ok'] === true || $check['ok'] === null,
        );

        return [
            'ok' => $ok,
            'checked_at' => now()->toIso8601String(),
            'hostname' => gethostname() ?: 'unknown',
            'checks' => $checks,
        ];
    }

    /** @return array{id: string, label: string, ok: bool|null, detail: string} */
    protected function checkApp(): array
    {
        return [
            'id' => 'app',
            'label' => 'App process',
            'ok' => true,
            'detail' => 'PHP '.PHP_VERSION.' · '.config('app.env'),
        ];
    }

    /** @return array{id: string, label: string, ok: bool|null, detail: string} */
    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return [
                'id' => 'database',
                'label' => 'Database',
                'ok' => true,
                'detail' => 'Connection OK ('.(string) config('database.default').')',
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'database',
                'label' => 'Database',
                'ok' => false,
                'detail' => $this->shortError($e),
            ];
        }
    }

    /** @return array{id: string, label: string, ok: bool|null, detail: string} */
    protected function checkRedis(): array
    {
        if (config('cache.default') !== 'redis' && config('queue.default') !== 'redis') {
            return [
                'id' => 'redis',
                'label' => 'Redis',
                'ok' => null,
                'detail' => 'Not configured as default cache/queue — skipped',
            ];
        }

        try {
            Cache::store('redis')->put('platform:health:ping', '1', 5);
            $ok = Cache::store('redis')->get('platform:health:ping') === '1';

            return [
                'id' => 'redis',
                'label' => 'Redis',
                'ok' => $ok,
                'detail' => $ok ? 'Read/write OK' : 'Ping failed',
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'redis',
                'label' => 'Redis',
                'ok' => false,
                'detail' => $this->shortError($e),
            ];
        }
    }

    /** @return array{id: string, label: string, ok: bool|null, detail: string} */
    protected function checkQueue(): array
    {
        $driver = (string) config('queue.default', 'sync');

        if ($driver === 'sync') {
            return [
                'id' => 'queue',
                'label' => 'Queue worker',
                'ok' => null,
                'detail' => 'Using sync driver (jobs run inline — no worker required)',
            ];
        }

        try {
            $pending = Queue::size();
            $failed = 0;
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $failed = (int) DB::table('failed_jobs')->count();
            }

            $token = (string) Str::uuid();
            Cache::forget(self::QUEUE_PROBE_KEY);
            dispatch(function () use ($token) {
                Cache::put(self::QUEUE_PROBE_KEY, $token, now()->addMinutes(5));
            });

            $processed = false;
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                if (Cache::get(self::QUEUE_PROBE_KEY) === $token) {
                    $processed = true;
                    break;
                }
                usleep(150_000);
            }

            if ($processed) {
                return [
                    'id' => 'queue',
                    'label' => 'Queue worker',
                    'ok' => true,
                    'detail' => "Worker processed probe · pending {$pending} · failed {$failed} · driver {$driver}",
                ];
            }

            return [
                'id' => 'queue',
                'label' => 'Queue worker',
                'ok' => false,
                'detail' => "Probe job not processed within 3s — is queue:work running? pending {$pending} · failed {$failed} · driver {$driver}",
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'queue',
                'label' => 'Queue worker',
                'ok' => false,
                'detail' => $this->shortError($e),
            ];
        }
    }

    /** @return array{id: string, label: string, ok: bool|null, detail: string} */
    protected function checkScheduler(): array
    {
        $raw = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        if (! $raw) {
            return [
                'id' => 'scheduler',
                'label' => 'Scheduler',
                'ok' => false,
                'detail' => 'No heartbeat yet — ensure cron runs `php artisan schedule:run` every minute',
            ];
        }

        try {
            $at = \Illuminate\Support\Carbon::parse((string) $raw);
            $ageSec = max(0, now()->diffInSeconds($at));
            $ok = $ageSec <= 120;

            return [
                'id' => 'scheduler',
                'label' => 'Scheduler',
                'ok' => $ok,
                'detail' => $ok
                    ? "Heartbeat {$ageSec}s ago ({$at->toDateTimeString()})"
                    : "Stale heartbeat ({$ageSec}s ago) — schedule:run may be stopped",
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'scheduler',
                'label' => 'Scheduler',
                'ok' => false,
                'detail' => $this->shortError($e),
            ];
        }
    }

    /** @return array{id: string, label: string, ok: bool|null, detail: string} */
    protected function checkReverb(): array
    {
        $reachability = $this->reverbReachability();

        return [
            'id' => 'reverb',
            'label' => 'Reverb (realtime)',
            'ok' => $reachability['ok'],
            'detail' => $reachability['detail'],
        ];
    }

    /**
     * Public helper used by the dedicated “send test notification” action.
     *
     * @return array{ok: bool, detail: string, host?: string, port?: int}
     */
    public function reverbReachability(): array
    {
        $key = (string) env('REVERB_APP_KEY', '');
        $host = (string) env('REVERB_HOST', config('reverb.servers.reverb.hostname', ''));
        $port = (int) env('REVERB_PORT', config('reverb.servers.reverb.port', 8080));
        $scheme = (string) env('REVERB_SCHEME', 'http');

        if ($key === '' || $host === '') {
            return [
                'ok' => false,
                'detail' => 'REVERB_APP_KEY / REVERB_HOST not configured',
            ];
        }

        if (! $this->tcpReachable($host, $port, 1.5)) {
            return [
                'ok' => false,
                'host' => $host,
                'port' => $port,
                'detail' => "Cannot reach {$scheme}://{$host}:{$port} — is `php artisan reverb:start` running?",
            ];
        }

        $broadcast = (string) config('broadcasting.default', 'null');
        if ($broadcast === '' || $broadcast === 'null') {
            return [
                'ok' => false,
                'host' => $host,
                'port' => $port,
                'detail' => "Port open, but BROADCAST_CONNECTION is \"{$broadcast}\" — set to reverb to send events",
            ];
        }

        return [
            'ok' => true,
            'host' => $host,
            'port' => $port,
            'detail' => "Config OK · {$host}:{$port} reachable · broadcast={$broadcast}",
        ];
    }

    /**
     * @return list<array{id: string, label: string, ok: bool|null, detail: string}>
     */
    protected function checkPeerPods(): array
    {
        $raw = trim((string) env('PLATFORM_PEER_HEALTH_URLS', ''));
        if ($raw === '') {
            return [[
                'id' => 'peers',
                'label' => 'Other pods',
                'ok' => null,
                'detail' => 'Set PLATFORM_PEER_HEALTH_URLS (comma-separated API bases) to probe siblings',
            ]];
        }

        $urls = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $results = [];

        foreach ($urls as $index => $base) {
            $base = rtrim($base, '/');
            $probeUrl = str_contains($base, '/health')
                ? $base
                : $base.'/api/v1/health?connectivity=1';

            $id = 'peer-'.($index + 1);
            try {
                $response = Http::timeout(3)->acceptJson()->get($probeUrl);
                $ok = $response->successful() && ($response->json('ok') ?? true);
                $ms = (int) ($response->json('server_ms') ?? 0);
                $results[] = [
                    'id' => $id,
                    'label' => 'Pod · '.$this->shortHost($base),
                    'ok' => $ok,
                    'detail' => $ok
                        ? "HTTP {$response->status()}".($ms > 0 ? " · {$ms}ms" : '')
                        : "HTTP {$response->status()} · {$probeUrl}",
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'id' => $id,
                    'label' => 'Pod · '.$this->shortHost($base),
                    'ok' => false,
                    'detail' => $this->shortError($e),
                ];
            }
        }

        return $results;
    }

    protected function tcpReachable(string $host, int $port, float $timeoutSec): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    protected function shortHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    protected function shortError(\Throwable $e): string
    {
        return Str::limit($e->getMessage(), 160);
    }
}
