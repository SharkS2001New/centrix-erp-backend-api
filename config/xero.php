<?php

return [
    'client_id' => env('XERO_CLIENT_ID'),
    'client_secret' => env('XERO_CLIENT_SECRET'),
    'redirect_uri' => env('XERO_REDIRECT_URI', env('APP_URL').'/api/v1/accounting/xero/callback'),
    'authorization_url' => 'https://login.xero.com/identity/connect/authorize',
    'token_url' => 'https://identity.xero.com/connect/token',
    'api_base_url' => 'https://api.xero.com',
];
