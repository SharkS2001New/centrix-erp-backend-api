<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformDemoDataService;

class PlatformDemoDataController extends Controller
{
    public function show(PlatformDemoDataService $demo)
    {
        return response()->json($demo->status());
    }

    public function store(PlatformDemoDataService $demo)
    {
        $status = $demo->generate();

        return response()->json([
            'message' => 'Demo organisations are ready. Sign in with the company code, username admin, and the demo password.',
            ...$status,
        ]);
    }

    public function destroy(PlatformDemoDataService $demo)
    {
        $result = $demo->rollback();

        return response()->json([
            'message' => ($result['removed'] ?? 0) > 0
                ? 'Demo organisations were removed.'
                : 'There was no demo data to remove.',
            ...$result,
        ]);
    }
}
