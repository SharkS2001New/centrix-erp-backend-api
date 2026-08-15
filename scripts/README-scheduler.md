Scheduler install and verification

1) Install cron entry (runs as current user):

   sudo scripts/setup-scheduler.sh /path/to/centrix-erp-backend-api

2) Verify heartbeat (should return ISO timestamp):

   scripts/check-scheduler-heartbeat.sh /path/to/centrix-erp-backend-api

3) Manually test a device sync:

   scripts/sync-device.sh DEVICE_NO

Notes:
- The cron installer appends a line to the crontab for the invoking user. If your deployment uses systemd timers or container cron, adapt accordingly.
- `php artisan migrate` should be run on the server to apply `2026_08_14_210000_attendance_source_hr_applied.php` so the DB enum includes `hr_applied`. If you don't want to run migrations immediately, the runtime normalization added to `AttendanceClockPunchService` will gracefully fallback.
