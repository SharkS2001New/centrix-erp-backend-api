<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('erp:database-backup')
    ->dailyAt(config('backup.schedule_time', '02:00'))
    ->when(fn () => (bool) config('backup.enabled', true))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));

Schedule::command('erp:release-expired-stock-reservations')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('erp:expire-stale-orders')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/expire-stale-orders.log'));

Schedule::command('erp:backfill-sale-routes')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('erp:recover-stale-background-tasks')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('erp:send-system-issue-digest')
    ->dailyAt(config('system_issues.digest_time', '18:00'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/system-issue-digest.log'));

Schedule::command('erp:send-subscription-renewal-reminders')
    ->dailyAt(config('platform_billing.renewal_reminder_time', '09:00'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/subscription-renewal-reminders.log'));

Schedule::command('erp:close-idle-mobile-rep-attendance')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/close-idle-mobile-rep-attendance.log'));

Schedule::command('erp:warm-completed-sales-cache')
    ->dailyAt(config('completed_sales_cache.schedule_daily_at', '01:30'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/warm-completed-sales-cache.log'));

Schedule::command('erp:warm-completed-sales-cache --days=3')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/warm-completed-sales-cache-hourly.log'));
