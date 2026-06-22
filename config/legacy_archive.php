<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LightStores legacy archive (read-only sales)
    |--------------------------------------------------------------------------
    |
    | Master data (products, customers, routes, users) should live in Centrix
    | after legacy:import-lightstores --master-data. This archive connection is
    | for historical sales only — browse, report, or materialize on demand.
    | Restore LightStoresDBBackup.sql into LEGACY_DB_DATABASE first.
    |
    */
    'enabled' => filter_var(env('LEGACY_ARCHIVE_ENABLED', false), FILTER_VALIDATE_BOOL),

    'connection' => env('LEGACY_ARCHIVE_CONNECTION', 'legacy'),

    /** Sales on or before this date are treated as archive-era when merging reports. */
    'cutover_date' => env('LEGACY_ARCHIVE_CUTOVER_DATE'),

    'label' => env('LEGACY_ARCHIVE_LABEL', 'LightStores archive'),

];
