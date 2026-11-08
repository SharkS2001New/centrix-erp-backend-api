<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stale background task recovery
    |--------------------------------------------------------------------------
    */
    'stale_pending_minutes' => (int) env('BACKGROUND_STALE_PENDING_MINUTES', 15),
    /**
     * Must exceed typical report/export job timeouts (often 3600s) or recoverStale*
     * marks healthy long jobs failed mid-run.
     */
    'stale_running_minutes' => (int) env('BACKGROUND_STALE_RUNNING_MINUTES', 70),
    /** Longer threshold for catalog import tasks that may run up to an hour. */
    'stale_import_running_minutes' => (int) env('BACKGROUND_STALE_IMPORT_RUNNING_MINUTES', 90),
    /** Report export / paginated fetch / backup — align with queue:work --timeout=3600. */
    'stale_export_running_minutes' => (int) env('BACKGROUND_STALE_EXPORT_RUNNING_MINUTES', 75),

    /*
    | When true, if a job is still pending after the HTTP response, the API process
    | runs it with dispatchSync. Useful without a worker (sync/local). Dangerous in
    | production Redis fleets — large jobs exhaust PHP-FPM and look like "offline".
    | null = enable only when QUEUE_CONNECTION=sync.
    */
    'inline_fallback' => env('BACKGROUND_INLINE_FALLBACK'),
    'inline_fallback_delay_ms' => (int) env('BACKGROUND_INLINE_FALLBACK_DELAY_MS', 750),

    /*
    |--------------------------------------------------------------------------
    | Export file directory (under storage/app)
    |--------------------------------------------------------------------------
    |
    | Must live on storage shared between API and queue-worker pods in Kubernetes.
    |
    */
    'export_directory' => env('BACKGROUND_EXPORT_DIRECTORY', 'private/backups/exports'),

    /*
    |--------------------------------------------------------------------------
    | Report export limits
    |--------------------------------------------------------------------------
    */
    'max_export_rows' => (int) env('BACKGROUND_MAX_EXPORT_ROWS', 100_000),
    'max_fetch_rows' => (int) env('BACKGROUND_MAX_FETCH_ROWS', 50_000),
    'pdf_max_rows' => (int) env('BACKGROUND_PDF_MAX_ROWS', 2_500),
    'inline_rows_max' => (int) env('BACKGROUND_INLINE_ROWS_MAX', 5_000),
    'fetch_per_page' => (int) env('BACKGROUND_FETCH_PER_PAGE', 500),
    /** Rows above this are stored on disk instead of in background_tasks.result JSON. */
    'result_inline_row_limit' => (int) env('BACKGROUND_RESULT_INLINE_ROW_LIMIT', 500),
];
