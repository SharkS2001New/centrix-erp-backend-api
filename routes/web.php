<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Unauthorized. Public access to this API is not permitted.',
        'hint' => 'Sign in through the Centrix ERP application using your organization credentials.',
        'application' => config('erp.frontend_url'),
    ], 403);
});
