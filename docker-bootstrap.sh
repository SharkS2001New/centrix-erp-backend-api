#!/bin/sh
# Database migrations, public storage link, and writable dirs.
# Used by docker-entrypoint.sh on every container start and by the Helm pre-upgrade Job.
set -e

mkdir -p resources/views
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p storage/app/public
mkdir -p storage/app/private/backups/database
mkdir -p storage/app/private/backups/exports
mkdir -p bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache resources 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage/app/private/backups 2>/dev/null || true

if [ "${RUN_MIGRATIONS_ON_START:-true}" != "false" ]; then
  echo "[bootstrap] Running database migrations..."
  php artisan migrate --force
else
  echo "[bootstrap] Skipping migrations (RUN_MIGRATIONS_ON_START=false)."
fi

if [ "${RUN_ROLE_TEMPLATES_ON_START:-true}" != "false" ]; then
  echo "[bootstrap] Syncing production role templates..."
  php artisan erp:sync-role-templates
else
  echo "[bootstrap] Skipping role templates (RUN_ROLE_TEMPLATES_ON_START=false)."
fi

if [ "${RUN_STORAGE_LINK_ON_START:-true}" != "false" ]; then
  echo "[bootstrap] Linking public storage..."
  php artisan storage:link --force
else
  echo "[bootstrap] Skipping storage:link (RUN_STORAGE_LINK_ON_START=false)."
fi

echo "[bootstrap] Done."
