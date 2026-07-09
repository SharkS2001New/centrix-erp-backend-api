<?php

return [
  'enabled' => env('COMPLETED_SALES_CACHE_ENABLED', true),

  /** How many past calendar days the scheduler pre-warms (excluding today unless warm_today). */
  'warm_days' => (int) env('COMPLETED_SALES_CACHE_WARM_DAYS', 365),

  /** Also refresh today's terminal sales on each warmup run (mutable until end of day). */
  'warm_today' => env('COMPLETED_SALES_CACHE_WARM_TODAY', true),

  /**
   * Stored sale statuses treated as completed / immutable for caching.
   * Returns and payment reversals invalidate individual entries.
   */
  'terminal_statuses' => ['completed', 'delivered', 'paid', 'processed'],

  /** Scheduler: daily full warm + hourly refresh for today. */
  'schedule_daily_at' => env('COMPLETED_SALES_CACHE_DAILY_AT', '01:30'),
];
