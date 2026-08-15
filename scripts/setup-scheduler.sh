#!/usr/bin/env bash
# Installs a crontab entry to run the Laravel scheduler every minute.
# Usage: sudo scripts/setup-scheduler.sh /path/to/project

PROJECT_DIR="${1:-$(pwd)}"
USER="${SUDO_USER:-$(whoami)}"
CRON_LINE="* * * * * cd ${PROJECT_DIR} && php artisan schedule:run >> ${PROJECT_DIR}/storage/logs/schedule-run.log 2>&1"

echo "Installing scheduler crontab for user: ${USER}"
# Install without duplicating existing lines
( crontab -l 2>/dev/null | grep -v -F "${CRON_LINE}" || true; echo "${CRON_LINE}" ) | crontab -

echo "Crontab installed. Check ${PROJECT_DIR}/storage/logs/schedule-run.log for output." 

exit 0
