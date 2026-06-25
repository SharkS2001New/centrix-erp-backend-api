<?php

use App\Support\EnvironmentSettings;

return [
    'name' => env('APP_NAME', 'Centrix ERP API'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => EnvironmentSettings::value('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Africa/Nairobi'),
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => EnvironmentSettings::value('APP_KEY'),
    'previous_keys' => [
        ...array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
