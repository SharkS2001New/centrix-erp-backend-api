<?php

return [
  /*
  |--------------------------------------------------------------------------
  | Stale background task recovery
  |--------------------------------------------------------------------------
  |
  | Queue workers can die without marking a task failed. Pending/running rows
  | then block new exports. Tasks older than these thresholds are auto-failed.
  |
  */
  'stale_pending_minutes' => (int) env('BACKGROUND_STALE_PENDING_MINUTES', 15),
  'stale_running_minutes' => (int) env('BACKGROUND_STALE_RUNNING_MINUTES', 35),
];
