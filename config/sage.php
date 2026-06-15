<?php

return [
    'client_id' => env('SAGE_CLIENT_ID'),
    'client_secret' => env('SAGE_CLIENT_SECRET'),
    'redirect_uri' => env('SAGE_REDIRECT_URI', env('APP_URL').'/api/v1/accounting/sage/callback'),
    'api_base_url' => env('SAGE_API_BASE_URL', 'https://api.columbus.sage.com'),
];
