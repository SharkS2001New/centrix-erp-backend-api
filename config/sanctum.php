<?php

$expirationMinutes = (int) config('security.sanctum_token_expiration_minutes', 60 * 24);

return [
    'stateful' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost')),
    ))),
    'guard' => ['web'],
    'expiration' => $expirationMinutes > 0 ? $expirationMinutes : null,
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
