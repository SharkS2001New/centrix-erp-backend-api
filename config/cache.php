<?php

use Illuminate\Support\Str;

return [
    'default' => env('CACHE_STORE', 'database'),

    'organization_ttl' => (int) env('CACHE_ORGANIZATION_TTL', 3600),

    'reports_dashboard_ttl' => (int) env('CACHE_REPORTS_DASHBOARD_TTL', 180),

    'hub_summary_ttl' => (int) env('CACHE_HUB_SUMMARY_TTL', 120),

    'reports_eod_ttl' => (int) env('CACHE_REPORTS_EOD_TTL', 120),

    'mobile_dashboard_ttl' => (int) env('CACHE_MOBILE_DASHBOARD_TTL', 60),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'centrix_erp_cache'),
];
