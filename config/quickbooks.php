<?php

return [
    'client_id' => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI', env('APP_URL').'/api/v1/accounting/quickbooks/callback'),
    'environment' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox'),
    'scope' => 'com.intuit.quickbooks.accounting',
    'authorization_url' => 'https://appcenter.intuit.com/connect/oauth2',
    'token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
    'api_base_url' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox') === 'production'
        ? 'https://quickbooks.api.intuit.com'
        : 'https://sandbox-quickbooks.api.intuit.com',
];
