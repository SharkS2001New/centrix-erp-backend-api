<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceMissedPunchesService;
use App\Services\Attendance\Hikvision\HikvisionAttendanceSyncService;
use App\Support\AppTimezone;
use Illuminate\Http\Request;

class AttendanceMissedPunchesController extends Controller
{
    public function __construct(
        protected AttendanceMissedPunchesService $missedPunches,
        protected HikvisionAttendanceSyncService $deviceSync,
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

    /** POST /attendance/sync-from-devices */
    public function syncFromDevices(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = isset($data['from'])
            ? AppTimezone::parseDateStart((string) $data['from'])
            : null;
        $to = isset($data['to'])
            ? AppTimezone::parseDateEnd((string) $data['to'])
            : null;

        return response()->json($this->deviceSync->syncOrganization((int) $orgId, $from, $to));
    }
}
