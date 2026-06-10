<?php

return [
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
