#!/bin/sh
set -e

# Framework dirs are gitignored locally and were excluded from older images via .dockerignore.
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

php artisan config:cache
php artisan view:cache 2>/dev/null || true

exec "$@"
