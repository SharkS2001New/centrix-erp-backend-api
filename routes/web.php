<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'product' => config('branding.product_name'),
    'version' => '3.1',
    'api' => url('/api/v1'),
    'postman' => 'Import postman/POS-ERP-API.postman_collection.json',
]));
