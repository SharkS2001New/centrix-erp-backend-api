<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) config('security.cors_allowed_origins', 'http://localhost:3000')),
    ))),
    'allowed_origins_patterns' => [
        // Flutter web dev server (random localhost / 127.0.0.1 ports).
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => filter_var(
        env('CORS_SUPPORTS_CREDENTIALS', env('WEB_COOKIE_AUTH', false)),
        FILTER_VALIDATE_BOOL,
    ),
];
