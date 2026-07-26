<?php

return [
    /*
    |--------------------------------------------------------------------------
    | QZ Tray signing (production silent print)
    |--------------------------------------------------------------------------
    |
    | Optional. Without these, the frontend can still use QZ Tray with trust
    | prompts (fine for development). When set, Admin → Local printing can
    | enable “Use server certificate signing”.
    |
    | @see https://qz.io/docs/signing
    */
    'certificate' => env('QZ_CERTIFICATE'),
    'private_key' => env('QZ_PRIVATE_KEY'),
    'certificate_path' => env('QZ_CERTIFICATE_PATH'),
    'private_key_path' => env('QZ_PRIVATE_KEY_PATH'),
];
