#!/bin/sh
# Database migrations, permission registry sync, public storage link, and writable dirs.
# Used by docker-entrypoint.sh on every container start and by the Helm pre-upgrade Job.
#
# Env toggles (all default to "run on start"):
#   RUN_MIGRATIONS_ON_START=false          — skip php artisan migrate --force
#   RUN_PERMISSIONS_SYNC_ON_START=false    — skip php artisan erp:permissions-sync
#   RUN_STORAGE_LINK_ON_START=false        — skip php artisan storage:link --force
#   PUBLIC_STORAGE_DRIVER=r2|s3            — also skips storage:link (objects live in cloud)
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
  echo "[bootstrap] Pending migrations:"
  php artisan migrate:status --pending 2>/dev/null || true
  echo "[bootstrap] Running database migrations..."
  php artisan migrate --force
else
  echo "[bootstrap] Skipping migrations (RUN_MIGRATIONS_ON_START=false)."
fi

if [ "${RUN_PERMISSIONS_SYNC_ON_START:-true}" != "false" ]; then
  echo "[bootstrap] Syncing permission registry..."
  php artisan erp:permissions-sync
else
  echo "[bootstrap] Skipping permission sync (RUN_PERMISSIONS_SYNC_ON_START=false)."
fi

PUBLIC_DRIVER="$(printf '%s' "${PUBLIC_STORAGE_DRIVER:-local}" | tr '[:upper:]' '[:lower:]')"
if [ "${RUN_STORAGE_LINK_ON_START:-true}" = "false" ]; then
  echo "[bootstrap] Skipping storage:link (RUN_STORAGE_LINK_ON_START=false)."
elif [ "$PUBLIC_DRIVER" = "r2" ] || [ "$PUBLIC_DRIVER" = "s3" ]; then
  echo "[bootstrap] Skipping storage:link (PUBLIC_STORAGE_DRIVER=${PUBLIC_DRIVER}; public files are remote)."
else
  echo "[bootstrap] Linking public storage..."
  php artisan storage:link --force
fi

echo "[bootstrap] Done."
