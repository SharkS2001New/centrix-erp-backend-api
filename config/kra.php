<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Automatic KRA recovery (queued receipts / product registration)
    |--------------------------------------------------------------------------
    |
    | Soft-failed fiscal receipts and transient product-registration failures
    | are retried automatically only while KRA is enabled for the organization.
    | After max attempts (at staggered intervals), the UI shows a manual-retry
    | label and operators finish the upload themselves.
    |
    */
    'auto_retry_max_attempts' => max(1, (int) env('KRA_AUTO_RETRY_MAX_ATTEMPTS', 3)),

    /** Seconds to wait after 0, 1, then 2+ completed auto attempts. */
    'auto_retry_delay_seconds' => [
        max(60, (int) env('KRA_AUTO_RETRY_DELAY_1', 5 * 60)),
        max(60, (int) env('KRA_AUTO_RETRY_DELAY_2', 20 * 60)),
        max(60, (int) env('KRA_AUTO_RETRY_DELAY_3', 60 * 60)),
    ],
];
