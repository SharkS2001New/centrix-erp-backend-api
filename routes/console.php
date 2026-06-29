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

Schedule::command('erp:recover-stale-background-tasks')
    ->everyFiveMinutes()
    ->withoutOverlapping();
