<?php

$publicDriver = strtolower((string) env('PUBLIC_STORAGE_DRIVER', 'local'));

/*
| Product photos, shop images, and other org files live on the "public" disk.
| Keys are always org-scoped: orgs/{COMPANY_CODE}/products/{product_code}/…
|
| Drivers:
|   local — storage/app/public (default)
|   s3    — any S3-compatible store (AWS S3, MinIO) via AWS_*
|   r2    — Cloudflare R2 via PUBLIC_R2_* (falls back to BACKUP_R2_* if unset)
*/
$publicDisk = match ($publicDriver) {
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => filter_var(env('AWS_USE_PATH_STYLE_ENDPOINT', true), FILTER_VALIDATE_BOOL),
        'throw' => false,
    ],
    'r2' => [
        'driver' => 's3',
        'key' => env('PUBLIC_R2_ACCESS_KEY_ID', env('BACKUP_R2_ACCESS_KEY_ID')),
        'secret' => env('PUBLIC_R2_SECRET_ACCESS_KEY', env('BACKUP_R2_SECRET_ACCESS_KEY')),
        'region' => env('PUBLIC_R2_REGION', env('BACKUP_R2_REGION', 'auto')),
        'bucket' => env('PUBLIC_R2_BUCKET', env('BACKUP_R2_BUCKET')),
        'url' => env('PUBLIC_R2_PUBLIC_URL', env('BACKUP_R2_PUBLIC_URL')),
        'endpoint' => env('PUBLIC_R2_ENDPOINT', env('BACKUP_R2_ENDPOINT')),
        'use_path_style_endpoint' => filter_var(
            env('PUBLIC_R2_USE_PATH_STYLE_ENDPOINT', env('BACKUP_R2_USE_PATH_STYLE_ENDPOINT', true)),
            FILTER_VALIDATE_BOOL,
        ),
        'throw' => false,
    ],
    default => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => env('APP_URL').'/storage',
        'visibility' => 'public',
        'throw' => false,
    ],
};

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private')],
        'public' => $publicDisk,
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(env('AWS_USE_PATH_STYLE_ENDPOINT', true), FILTER_VALIDATE_BOOL),
            'throw' => false,
        ],
        'r2' => [
            'driver' => 's3',
            'key' => env('BACKUP_R2_ACCESS_KEY_ID'),
            'secret' => env('BACKUP_R2_SECRET_ACCESS_KEY'),
            'region' => env('BACKUP_R2_REGION', 'auto'),
            'bucket' => env('BACKUP_R2_BUCKET'),
            'url' => env('BACKUP_R2_PUBLIC_URL'),
            'endpoint' => env('BACKUP_R2_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(env('BACKUP_R2_USE_PATH_STYLE_ENDPOINT', true), FILTER_VALIDATE_BOOL),
            'throw' => true,
        ],
    ],
    'links' => [public_path('storage') => storage_path('app/public')],
];
