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

  /*
  |--------------------------------------------------------------------------
  | Export file directory (under storage/app)
  |--------------------------------------------------------------------------
  |
  | Must live on storage shared between API and queue-worker pods in Kubernetes.
  |
  */
  'export_directory' => env('BACKGROUND_EXPORT_DIRECTORY', 'private/backups/exports'),
];
