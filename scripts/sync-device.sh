#!/usr/bin/env bash
# Run a manual sync for a single Hikvision device and tail the sync log.
# Usage: scripts/sync-device.sh DEVICE_NO

DEVICE_NO="$1"
if [ -z "$DEVICE_NO" ]; then
  echo "Usage: $0 DEVICE_NO"
  exit 2
fi
php artisan erp:sync-hikvision-attendance --device="$DEVICE_NO"

echo "---- tail of sync log ----"
tail -n 200 storage/logs/sync-hikvision-attendance.log || true
