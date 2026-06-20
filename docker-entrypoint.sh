#!/bin/sh
set -e

# Runtime env (DB, Redis, etc.) is injected before Apache starts.
php artisan config:cache

exec "$@"
