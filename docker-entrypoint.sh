#!/bin/sh
set -e

# Runtime env (APP_KEY, DB, etc.) is injected by the orchestrator before Apache starts.
php artisan config:cache
php artisan view:cache || true

exec "$@"
