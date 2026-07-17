<?php

/**
 * WebAuthn / passkeys (GitHub-style).
 *
 * RP ID must be the registrable domain shared by the frontend origin
 * (e.g. app.centrix.example → centrix.example, or localhost for local).
 */
$frontend = rtrim((string) config('erp.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
$frontendHost = parse_url($frontend, PHP_URL_HOST) ?: 'localhost';

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) config('security.cors_allowed_origins', $frontend)),
)));
if ($origins === []) {
    $origins = [$frontend];
}

return [
    'rp_name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'Centrix ERP')),
    'rp_id' => env('WEBAUTHN_RP_ID', $frontendHost === '127.0.0.1' ? 'localhost' : $frontendHost),
    'allowed_origins' => $origins,
    'timeout_ms' => (int) env('WEBAUTHN_TIMEOUT_MS', 60_000),
];
