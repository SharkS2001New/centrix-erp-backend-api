#!/bin/sh
set -e

/usr/local/bin/docker-bootstrap.sh

if [ "${APP_ENV:-local}" = "production" ] && [ "${APP_DEBUG:-false}" = "true" ]; then
  echo "WARNING: APP_DEBUG=true in production — disable before serving traffic." >&2
fi

if [ "${APP_ENV:-local}" = "production" ] && [ -z "${CORS_ALLOWED_ORIGINS:-}" ] && [ -z "${FRONTEND_URL:-}" ]; then
  echo "WARNING: CORS_ALLOWED_ORIGINS and FRONTEND_URL are unset — browser clients may be blocked." >&2
fi

php artisan config:cache
php artisan event:cache 2>/dev/null || true
# Route/view caches skip compiling ~1k routes on every Apache prefork request.
# Fail soft if a deploy still has a closure route (route:cache refuses those).
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

exec "$@"
