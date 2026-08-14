<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceMissedPunchesService;
use Illuminate\Http\Request;

class AttendanceMissedPunchesController extends Controller
{
    public function __construct(
        protected AttendanceMissedPunchesService $missedPunches,
    ) {
    }

    /** GET /attendance/missed-punches */
    public function index(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        return response()->json($this->missedPunches->listForOrganization((int) $orgId));
    }

    /** POST /attendance/missed-punches/retry */
    public function retry(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        return response()->json($this->missedPunches->retryUnapplied((int) $orgId));
    }
}
