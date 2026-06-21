<?php

return [

    /*
    | Proxies (Traefik / ingress) that may set X-Forwarded-* headers.
    | Use "*" behind k3s ingress, or comma-separated CIDRs for tighter scope.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),

    /*
    | Comma-separated browser origins allowed to call the API (CORS).
    | Local default includes Next.js dev server.
    */
    'cors_allowed_origins' => env('CORS_ALLOWED_ORIGINS')
        ?: implode(',', array_unique(array_filter([
            rtrim((string) env('FRONTEND_URL', ''), '/'),
            'http://localhost:3000',
            'http://127.0.0.1:3000',
        ]))),

    'cors_supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', false),

    /*
    | When true, idle Sanctum tokens are revoked server-side (erp.session_idle middleware).
    | Default false — the web app lock screen handles inactivity UX client-side.
    */
    'revoke_idle_tokens' => filter_var(env('AUTH_SERVER_IDLE_REVOKE', false), FILTER_VALIDATE_BOOL),

    /*
    | HttpOnly API token cookie for browser clients (backoffice/POS web).
    | Mobile keeps Bearer tokens. Requires CORS_SUPPORTS_CREDENTIALS=true.
    */
    'api_token_cookie' => [
        'enabled' => env('WEB_COOKIE_AUTH', false),
        'name' => env('API_TOKEN_COOKIE_NAME', 'centrix_api_token'),
        'domain' => env('API_TOKEN_COOKIE_DOMAIN'),
        'secure' => env('API_TOKEN_COOKIE_SECURE', env('APP_ENV') === 'production'),
        'same_site' => env('API_TOKEN_COOKIE_SAME_SITE', 'none'),
    ],

    'sanctum_token_expiration_minutes' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 24),

    /** Per login_channel overrides (minutes). Falls back to sanctum_token_expiration_minutes. */
    'token_expiration_minutes_by_channel' => [
        'backoffice' => (int) env('SANCTUM_TOKEN_EXPIRATION_BACKOFFICE', 480),
        'pos' => (int) env('SANCTUM_TOKEN_EXPIRATION_POS', 1440),
        'mobile' => (int) env('SANCTUM_TOKEN_EXPIRATION_MOBILE', 1440),
    ],

    /*
    | Reject M-Pesa payment callbacks unless the client IP is in Safaricom ranges.
    | Disable in local/testing (MPESA_CALLBACK_IP_CHECK=false).
    */
    'mpesa_callback_ip_check' => env('MPESA_CALLBACK_IP_CHECK', true),

    /** @var list<string> Safaricom Daraja callback CIDR blocks */
    'mpesa_callback_cidrs' => [
        '196.201.214.0/24',
        '196.201.213.0/24',
        '196.201.212.0/24',
    ],

    /** @var list<string> Additional Safaricom callback IPs (documented individually) */
    'mpesa_callback_ips' => [
        '196.201.214.200',
        '196.201.214.206',
        '196.201.213.114',
        '196.201.214.207',
        '196.201.214.208',
        '196.201.213.44',
        '196.201.212.127',
        '196.201.212.128',
        '196.201.212.129',
        '196.201.212.132',
        '196.201.212.136',
        '196.201.212.138',
        '196.201.212.69',
        '196.201.212.74',
    ],

    'rate_limits' => [
        'auth_login' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_LOGIN', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_LOGIN_DECAY', 1),
        ],
        'auth_password' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_PASSWORD', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_PASSWORD_DECAY', 15),
        ],
        'auth_org_preview' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_ORG_PREVIEW', 10),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_ORG_PREVIEW_DECAY', 1),
        ],
        'api' => [
            'max_attempts' => (int) env('RATE_LIMIT_API', 120),
            'decay_minutes' => (int) env('RATE_LIMIT_API_DECAY', 1),
        ],
    ],

];
