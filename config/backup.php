<?php

return [
    'enabled' => filter_var(env('BACKUP_ENABLED', true), FILTER_VALIDATE_BOOL),

    'disk' => env('BACKUP_DISK', 'local'),

    'path' => env('BACKUP_PATH', 'backups/database'),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 7),

    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

    'notify_email' => env('BACKUP_NOTIFY_EMAIL'),

    'attach_max_bytes' => (int) env('BACKUP_ATTACH_MAX_BYTES', 0),

    'compress' => filter_var(env('BACKUP_COMPRESS', true), FILTER_VALIDATE_BOOL),

    'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),

    'expose_error_detail' => filter_var(env('BACKUP_EXPOSE_ERROR_DETAIL', true), FILTER_VALIDATE_BOOL),

    'r2' => [
        'enabled' => filter_var(env('BACKUP_R2_ENABLED', false), FILTER_VALIDATE_BOOL),
        'disk' => env('BACKUP_R2_DISK', 'r2'),
        'key' => env('BACKUP_R2_ACCESS_KEY_ID'),
        'secret' => env('BACKUP_R2_SECRET_ACCESS_KEY'),
        'region' => env('BACKUP_R2_REGION', 'auto'),
        'bucket' => env('BACKUP_R2_BUCKET'),
        // https://<ACCOUNT_ID>.r2.cloudflarestorage.com
        'endpoint' => env('BACKUP_R2_ENDPOINT'),
        'use_path_style_endpoint' => filter_var(env('BACKUP_R2_USE_PATH_STYLE_ENDPOINT', true), FILTER_VALIDATE_BOOL),
        'prefix' => env('BACKUP_R2_PREFIX', 'backups/database'),
        // Optional public/custom domain base for UI links, e.g. https://backups.example.com
        'public_url' => env('BACKUP_R2_PUBLIC_URL'),
    ],
];
