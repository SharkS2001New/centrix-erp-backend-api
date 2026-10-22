<?php

return [
    'enabled' => filter_var(env('PLATFORM_SPEED_ENABLED', true), FILTER_VALIDATE_BOOL),

    /** Persist per-request aggregates into Redis (then flush to MySQL). */
    'record_requests' => filter_var(env('PLATFORM_SPEED_RECORD_REQUESTS', true), FILTER_VALIDATE_BOOL),

    /** Listen to DB::listen for query fingerprints on each request. */
    'db_listen' => filter_var(env('PLATFORM_SPEED_DB_LISTEN', true), FILTER_VALIDATE_BOOL),

    /** Queries slower than this (ms) are counted in query fingerprint aggregates. */
    'slow_query_ms' => (int) env('PLATFORM_SPEED_SLOW_QUERY_MS', 500),

    /** Same fingerprint this many times in one request → N+1 suspect. */
    'n_plus_one_threshold' => (int) env('PLATFORM_SPEED_N_PLUS_ONE', 8),

    /** Keep minute rows this many days. */
    'retention_days' => (int) env('PLATFORM_SPEED_RETENTION_DAYS', 14),

    /**
     * Latency histogram upper bounds in ms (inclusive).
     * Last bucket is "above previous".
     */
    'histogram_bounds_ms' => [50, 100, 200, 400, 800, 1500, 3000, 5000, 10000],

    /** Path prefixes excluded from traffic aggregates (no leading slash required). */
    'exclude_path_prefixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'PLATFORM_SPEED_EXCLUDE_PREFIXES',
            'up,api/v1/health,api/v1/admin/platform-speed,api/v1/admin/platform-health'
        )),
    ))),

    /** User-Agent substrings treated as health checks (case-insensitive). */
    'exclude_user_agents' => ['kube-probe', 'GoogleHC', 'healthcheck', 'ELB-HealthChecker', 'Platform-Speed-Synthetic'],

    /** Redis key prefix for hot minute buckets. */
    'redis_prefix' => 'platform_speed:',

    'synthetic' => [
        'enabled' => filter_var(env('PLATFORM_SPEED_SYNTHETIC_ENABLED', true), FILTER_VALIDATE_BOOL),
        /** Optional Sanctum token for authenticated read-only checks. */
        'token' => env('PLATFORM_SPEED_SYNTHETIC_TOKEN'),
        'base_url' => rtrim((string) env('PLATFORM_SPEED_SYNTHETIC_BASE_URL', env('APP_URL', 'http://127.0.0.1:8000')), '/'),
    ],

    'alerts' => [
        'api_p95_ms' => (int) env('PLATFORM_SPEED_ALERT_API_P95_MS', 800),
        'error_rate_percent' => (float) env('PLATFORM_SPEED_ALERT_ERROR_RATE', 2.0),
        'queue_backlog' => (int) env('PLATFORM_SPEED_ALERT_QUEUE_BACKLOG', 100),
        'digest_avg_sec' => (float) env('PLATFORM_SPEED_ALERT_DIGEST_AVG_SEC', 1.0),
    ],
];
