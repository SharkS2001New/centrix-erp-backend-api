<?php

return [

    /*
    | Proxies (Traefik / ingress) that may set X-Forwarded-* headers.
    | Use "*" behind k3s ingress, or comma-separated CIDRs for tighter scope.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),

    /*
    | Comma-separated browser origins allowed to call the API (CORS).
    | Local default includes Next.js dev server. Flutter web dev ports are
    | covered via allowed_origins_patterns in config/cors.php.
    */
    'cors_allowed_origins' => env('CORS_ALLOWED_ORIGINS')
        ?: implode(',', array_unique(array_filter([
            rtrim((string) env('FRONTEND_URL', ''), '/'),
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ]))),

    'cors_supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', env('WEB_COOKIE_AUTH', false)), FILTER_VALIDATE_BOOL),

    'sanctum_token_expiration_minutes' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 24),

    /*
    | Server-side idle token revocation (401 session_idle_timeout).
    | Keep false when the web app lock screen handles inactivity locally.
    */
    'revoke_idle_tokens' => filter_var(env('AUTH_SERVER_IDLE_REVOKE', false), FILTER_VALIDATE_BOOL),

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
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_LOGIN', 15),
            'max_attempts_per_ip' => (int) env('RATE_LIMIT_AUTH_LOGIN_PER_IP', 120),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_LOGIN_DECAY', 1),
        ],
        'auth_password' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_PASSWORD', 10),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_PASSWORD_DECAY', 15),
        ],
        'auth_org_preview' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_ORG_PREVIEW', 60),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_ORG_PREVIEW_DECAY', 1),
        ],
        'company_mobile_attendance' => [
            'max_attempts' => (int) env('RATE_LIMIT_COMPANY_MOBILE_ATTENDANCE', 120),
            'decay_minutes' => (int) env('RATE_LIMIT_COMPANY_MOBILE_ATTENDANCE_DECAY', 1),
        ],
        'api' => [
            'max_attempts' => (int) env('RATE_LIMIT_API', 120),
            'decay_minutes' => (int) env('RATE_LIMIT_API_DECAY', 1),
        ],
    ],

    /*
    | HttpOnly cookie auth for web clients (see WEB_COOKIE_AUTH).
    | Mobile keeps Bearer tokens in the JSON login response.
    */
    'api_token_cookie' => [
        'enabled' => filter_var(env('WEB_COOKIE_AUTH', false), FILTER_VALIDATE_BOOL),
        'name' => env('API_TOKEN_COOKIE_NAME', 'centrix_api_token'),
        'domain' => env('API_TOKEN_COOKIE_DOMAIN'),
        'secure' => filter_var(env('API_TOKEN_COOKIE_SECURE', true), FILTER_VALIDATE_BOOL),
        'same_site' => env('API_TOKEN_COOKIE_SAME_SITE', 'none'),
    ],

];
