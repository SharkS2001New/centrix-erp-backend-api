<?php

return [
    'enabled' => filter_var(env('BACKUP_ENABLED', true), FILTER_VALIDATE_BOOL),

    'disk' => env('BACKUP_DISK', 'local'),

    'path' => env('BACKUP_PATH', 'backups/database'),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

    'notify_email' => env('BACKUP_NOTIFY_EMAIL'),

    'attach_max_bytes' => (int) env('BACKUP_ATTACH_MAX_BYTES', 0),

    'compress' => filter_var(env('BACKUP_COMPRESS', true), FILTER_VALIDATE_BOOL),

    'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),

    'expose_error_detail' => filter_var(env('BACKUP_EXPOSE_ERROR_DETAIL', true), FILTER_VALIDATE_BOOL),

    'google_drive' => [
        'enabled' => filter_var(env('BACKUP_GOOGLE_DRIVE_ENABLED', false), FILTER_VALIDATE_BOOL),
        // service_account (default) or oauth — oauth is required for personal Gmail folders.
        'auth_mode' => env('BACKUP_GOOGLE_DRIVE_AUTH', 'service_account'),
        // Path to service account JSON inside the container (local dev or K8s volume mount).
        'credentials' => env('BACKUP_GOOGLE_DRIVE_CREDENTIALS'),
        // Raw JSON string — preferred on K8s (store in centrix-erp-env secret, no file mount).
        'credentials_json' => env('BACKUP_GOOGLE_DRIVE_CREDENTIALS_JSON'),
        'oauth_client_id' => env('BACKUP_GOOGLE_DRIVE_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('BACKUP_GOOGLE_DRIVE_OAUTH_CLIENT_SECRET'),
        'oauth_refresh_token' => env('BACKUP_GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN'),
        'oauth_redirect_uri' => env('BACKUP_GOOGLE_DRIVE_OAUTH_REDIRECT_URI', 'urn:ietf:wg:oauth:2.0:oob'),
        'folder_id' => env('BACKUP_GOOGLE_DRIVE_FOLDER_ID'),

        // Live check that the service account can see the target folder (diagnostics + before upload).
        'verify_folder_access' => filter_var(env('BACKUP_GOOGLE_DRIVE_VERIFY_ACCESS', true), FILTER_VALIDATE_BOOL),
    ],
];
