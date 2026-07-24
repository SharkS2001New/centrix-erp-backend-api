<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformPayrollScheduleSettingsResolver;
use Illuminate\Http\Request;

class PlatformPayrollScheduleSettingsController extends Controller
{
    public function show()
    {
        return response()->json(PlatformPayrollScheduleSettingsResolver::describe());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enforce_month_end_run_schedule' => 'sometimes|boolean',
        ]);

        return response()->json(PlatformPayrollScheduleSettingsResolver::save($data));
    }
}
