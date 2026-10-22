<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic Eloquent model auditing
    |--------------------------------------------------------------------------
    |
    | When true, every authenticated create/update/delete on almost every table
    | is written to audit_logs. That ballooned the table (hundreds of MB) and
    | slowed the app. Default is false: only explicit AuditLogger call sites
    | (CRUD controllers, checkout/payment/stock, login/logout, etc.) are kept.
    |
    */
    'model_events' => (bool) env('AUDIT_MODEL_EVENTS', false),

];
