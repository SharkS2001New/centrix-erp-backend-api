<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Legacy global M-Pesa defaults (deprecated for multi-tenant use)
    |--------------------------------------------------------------------------
    | Per-organization M-Pesa credentials and callback URLs are stored in
    | organizations.module_settings.finance.mpesa (Admin → Settings → Finance).
    | Branches may override till/shortcode in branches.settings.mpesa.
    */
    'consumer_key' => env('MPESA_CONSUMER_KEY', ''),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET', ''),
    'till_number' => env('MPESA_TILLNUMBER', ''),
    'shortcode' => env('MPESA_SHORTCODE', ''),
    'child_storecode' => env('MPESA_CHILD_STORECODE', ''),
    'passkey' => env('MPESA_PASSKEY', ''),
    'callback_url' => env('MPESA_CALLBACK_URL', ''),
    'confirmation_url' => env('MPESA_CONFIRMATION_URL', ''),
    'validation_url' => env('MPESA_VALIDATION_URL', ''),
    'env' => env('MPESA_ENV', 'sandbox'),
];
