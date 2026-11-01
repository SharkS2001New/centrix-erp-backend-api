<?php

return [
    /** Fallback digest recipient when Platform UI has not set one yet. */
    'digest_email' => env('SYSTEM_ISSUES_DIGEST_EMAIL', 'alpacke.tech@gmail.com'),

    /** Local time (app timezone) for the daily digest. */
    'digest_time' => env('SYSTEM_ISSUES_DIGEST_TIME', '18:00'),

    /** Same fingerprint this many times within the window → high priority. */
    'repeat_threshold' => (int) env('SYSTEM_ISSUES_REPEAT_THRESHOLD', 3),

    /** Rolling window (days) for grouping repetitive issues. */
    'repeat_window_days' => (int) env('SYSTEM_ISSUES_REPEAT_WINDOW_DAYS', 7),

    /** Automatically delete unresolved reports older than this many days. */
    'retention_days' => (int) env('SYSTEM_ISSUES_RETENTION_DAYS', 30),

    /** Automatically delete resolved reports this many days after resolution. */
    'resolved_retention_days' => (int) env('SYSTEM_ISSUES_RESOLVED_RETENTION_DAYS', 7),

    /** Local time (app timezone) for the daily prune job. */
    'prune_time' => env('SYSTEM_ISSUES_PRUNE_TIME', '03:15'),
];
