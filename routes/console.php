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

Schedule::command('inventory:finalize-pending-sale-stock --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/finalize-pending-sale-stock.log'));

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

Schedule::command('erp:send-ai-insights')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ai-insights-digest.log'));

Schedule::command('erp:send-hospitality-pos-email-reports')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/hospitality-pos-email-reports.log'));

Schedule::command('erp:release-expired-hotel-room-stays')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/release-expired-hotel-room-stays.log'));

Schedule::command('erp:run-hospitality-night-audit')
    ->dailyAt('00:30')
    ->timezone(config('app.timezone', 'Africa/Nairobi'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/hospitality-night-audit.log'));

Schedule::command('erp:prune-system-issue-reports')
    ->dailyAt(config('system_issues.prune_time', '03:15'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/prune-system-issue-reports.log'));

Schedule::command('erp:prune-platform-mail')
    ->dailyAt('03:25')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/prune-platform-mail.log'));

Schedule::command('erp:send-subscription-renewal-reminders')
    ->dailyAt(config('platform_billing.renewal_reminder_time', '09:00'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/subscription-renewal-reminders.log'));

Schedule::command('erp:send-debt-reminders')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/debt-reminders.log'));

Schedule::command('erp:close-idle-mobile-rep-attendance')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/close-idle-mobile-rep-attendance.log'));

Schedule::command('erp:mark-attendance-absents')
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/mark-attendance-absents.log'));

// Hikvision ISAPI poll is for on-prem / VPN only. Cloud Centrix cannot reach LAN
// device IPs — run attendance-agent on the office network instead.
// Schedule::command('erp:sync-hikvision-attendance')->everyFiveMinutes()...

Schedule::command('erp:warm-completed-sales-cache')
    ->dailyAt(config('completed_sales_cache.schedule_daily_at', '01:30'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/warm-completed-sales-cache.log'));

Schedule::command('erp:warm-completed-sales-cache --days=3')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/warm-completed-sales-cache-hourly.log'));

/** Heartbeat for platform infrastructure health UI (must see age < ~2 minutes). */
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put(
        \App\Services\Platform\PlatformHealthProbe::SCHEDULER_HEARTBEAT_KEY,
        now()->toIso8601String(),
        now()->addHours(2),
    );
})->everyMinute()->name('platform-scheduler-heartbeat');
