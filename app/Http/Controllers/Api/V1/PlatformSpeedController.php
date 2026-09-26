<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSpeedService;

class PlatformSpeedController extends Controller
{
    public function show(PlatformSpeedService $speed)
    {
        return response()->json($speed->snapshot());
    }
}
