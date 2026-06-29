<?php

return [
    /** Daily digest recipient for open / high-priority system issues. */
    'digest_email' => env('SYSTEM_ISSUES_DIGEST_EMAIL', 'alpacke.tech@gmail.com'),

    /** Local time (app timezone) for the daily digest. */
    'digest_time' => env('SYSTEM_ISSUES_DIGEST_TIME', '18:00'),

    /** Same fingerprint this many times within the window → high priority. */
    'repeat_threshold' => (int) env('SYSTEM_ISSUES_REPEAT_THRESHOLD', 3),

    /** Rolling window (days) for grouping repetitive issues. */
    'repeat_window_days' => (int) env('SYSTEM_ISSUES_REPEAT_WINDOW_DAYS', 7),
];
