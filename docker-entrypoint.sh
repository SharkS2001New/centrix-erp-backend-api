#!/bin/sh
set -e

mkdir -p storage/app/private/backups/database
mkdir -p resources/views
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache resources 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

if [ "${APP_ENV:-local}" = "production" ] && [ "${APP_DEBUG:-false}" = "true" ]; then
  echo "WARNING: APP_DEBUG=true in production — disable before serving traffic." >&2
fi

if [ "${APP_ENV:-local}" = "production" ] && [ -z "${CORS_ALLOWED_ORIGINS:-}" ] && [ -z "${FRONTEND_URL:-}" ]; then
  echo "WARNING: CORS_ALLOWED_ORIGINS and FRONTEND_URL are unset — browser clients may be blocked." >&2
fi

if [ "${WEB_COOKIE_AUTH:-false}" = "true" ] && [ "${CORS_SUPPORTS_CREDENTIALS:-}" != "true" ] && [ "${CORS_SUPPORTS_CREDENTIALS:-}" != "1" ]; then
  echo "NOTE: WEB_COOKIE_AUTH=true — CORS_SUPPORTS_CREDENTIALS will be enabled automatically at runtime." >&2
fi

if [ "${WEB_COOKIE_AUTH:-false}" = "true" ] && [ -z "${CORS_ALLOWED_ORIGINS:-}" ] && [ -z "${FRONTEND_URL:-}" ]; then
  echo "WARNING: Cookie auth requires CORS_ALLOWED_ORIGINS or FRONTEND_URL to match the web app origin." >&2
fi

php artisan config:cache

exec "$@"
