<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => 'POS ERP API',
    'version' => '3.1',
    'api' => url('/api/v1'),
    'postman' => 'Import postman/POS-ERP-API.postman_collection.json',
]));
