<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LightStores legacy archive
    |--------------------------------------------------------------------------
    |
    | Per-organization settings live in organizations.module_settings.legacy_archive
    | (super admin: PATCH /admin/organizations/{id}/settings/legacy-archive).
    |
    | Master data (products, customers, VAT, UOMs, suppliers, retail packages, routes)
    | belongs in Centrix via `php artisan legacy:import-lightstores --master-data`.
    | Each tenant may attach their own restored LightStores MySQL database for
    | historical sales only (read-only archive).
    |
    | database.connections.legacy supplies shared host/credentials defaults when an
    | organization does not override host, port, username, or password.
    |
    */
    'connection' => env('LEGACY_ARCHIVE_CONNECTION', 'legacy'),

];
