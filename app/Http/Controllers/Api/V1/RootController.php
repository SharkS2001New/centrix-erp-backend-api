<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RootController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthorized. Public access to this API is not permitted.',
            'hint' => 'Sign in through the Centrix ERP application using your organization credentials.',
            'application' => config('erp.frontend_url'),
        ], 403);
    }
}
