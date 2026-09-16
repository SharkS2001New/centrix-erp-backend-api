<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Operational data retention (scheduled prune)
    |--------------------------------------------------------------------------
    | Released cart/sale holds, KRA / Hikvision agent command bodies, attendance
    | history, audit noise, and terminal sales (cancelled / long-expired) are
    | purged to keep MySQL lean. Payroll figures stay on payroll_runs / lines.
    */
    'released_stock_reservations_days' => (int) env('RETENTION_RELEASED_STOCK_RESERVATIONS_DAYS', 14),

    'kra_agent_commands_completed_days' => (int) env('RETENTION_KRA_COMMANDS_COMPLETED_DAYS', 1),

    'kra_agent_commands_failed_days' => (int) env('RETENTION_KRA_COMMANDS_FAILED_DAYS', 2),

    /**
     * Completed Hikvision commands are deleted as soon as the API reads the body.
     * This is a safety net for leftovers (expired waiters, crashes).
     */
    'hikvision_agent_commands_completed_days' => (int) env('RETENTION_HIKVISION_COMMANDS_COMPLETED_DAYS', 1),

    'hikvision_agent_commands_failed_days' => (int) env('RETENTION_HIKVISION_COMMANDS_FAILED_DAYS', 2),

    /**
     * Terminal punch logs (applied, missed / outside window, duplicates) and forgotten
     * clock-out alerts — deleted / cleared after this many days.
     */
    'hikvision_access_events_days' => (int) env('RETENTION_HIKVISION_EVENTS_DAYS', 7),

    /**
     * Keep day-level attendance + clock sessions for ~2 months after the date
     * (payroll for that month is assumed settled).
     */
    'attendance_days' => (int) env('RETENTION_ATTENDANCE_DAYS', 60),

    'audit_logs_days' => (int) env('RETENTION_AUDIT_LOGS_DAYS', 10),

    /** Cancelled / deleted sales older than this (by cancelled_at) are hard-deleted. */
    'cancelled_sales_days' => (int) env('RETENTION_CANCELLED_SALES_DAYS', 7),

    /** Expired pipeline sales older than this (by expired_at) are hard-deleted. */
    'expired_sales_days' => (int) env('RETENTION_EXPIRED_SALES_DAYS', 14),

    'prune_time' => env('RETENTION_PRUNE_TIME', '03:40'),

    /*
    |--------------------------------------------------------------------------
    | Report / list hot windows
    |--------------------------------------------------------------------------
    */
    'report_max_range_days' => (int) env('REPORT_MAX_RANGE_DAYS', 90),

    'report_default_range_days' => (int) env('REPORT_DEFAULT_RANGE_DAYS', 30),
];
