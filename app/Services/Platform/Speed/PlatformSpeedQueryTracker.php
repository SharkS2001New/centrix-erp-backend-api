<?php

namespace App\Services\Platform\Speed;

use Illuminate\Database\Events\QueryExecuted;

/**
 * Request-scoped DB query fingerprints (bindings stripped).
 * Listener is registered once; {@see enableForRequest()} arms recording.
 */
class PlatformSpeedQueryTracker
{
    protected bool $active = false;

    /** @var array<string, int> */
    protected array $counts = [];

    public function __construct(
        protected PlatformSpeedMetricsRecorder $recorder,
    ) {}

    public function enableForRequest(): void
    {
        if (! config('platform_speed.db_listen')) {
            return;
        }
        $this->active = true;
        $this->counts = [];
    }

    public function disable(): void
    {
        $this->active = false;
        $this->counts = [];
    }

    public function handle(QueryExecuted $event): void
    {
        if (! $this->active) {
            return;
        }

        $sql = (string) $event->sql;
        if (stripos($sql, 'platform_speed_') !== false) {
            return;
        }

        $fingerprint = $this->fingerprint($sql);
        $preview = $this->preview($sql);
        $ms = (int) round((float) $event->time);
        $slowMs = (int) config('platform_speed.slow_query_ms', 500);
        $slow = $ms >= $slowMs;

        $this->counts[$fingerprint] = (int) ($this->counts[$fingerprint] ?? 0) + 1;
        $nPlus = $this->counts[$fingerprint] >= (int) config('platform_speed.n_plus_one_threshold', 8);

        $this->recorder->recordQuery($fingerprint, $preview, $ms, $slow, $nPlus);
    }

    public function fingerprint(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $normalized = preg_replace("/'[^']*'/", '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized) ?? $normalized;

        return hash('sha256', strtolower($normalized));
    }

    public function preview(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $normalized = preg_replace("/'[^']*'/", '?', $normalized) ?? $normalized;

        return substr($normalized, 0, 500);
    }
}
