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

    /** POST /attendance/missed-punches/auto-map */
    public function autoMap(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        return response()->json($this->missedPunches->autoMapAndRetry((int) $orgId));
    }

    /** POST /attendance/missed-punches/events/{event}/apply */
    public function applyEvent(Request $request, int $event)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        return response()->json($this->missedPunches->applyUnappliedEvent((int) $orgId, $event));
    }

    /** POST /attendance/missed-punches/{session}/clock-out */
    public function closeClockOut(Request $request, int $session)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        $data = $request->validate([
            'punched_at' => 'nullable|date',
            'clock_in_at' => 'nullable|date',
            'clock_out_at' => 'nullable|date',
            'confirm_reconciliation' => 'sometimes|boolean',
        ]);

        return response()->json(
            $this->missedPunches->closeMissingClockOut(
                (int) $orgId,
                $session,
                $data['clock_out_at'] ?? $data['punched_at'] ?? null,
                $data['clock_in_at'] ?? null,
                (bool) ($data['confirm_reconciliation'] ?? false),
            )
        );
    }

    /** POST /attendance/duplicate-punches/dismiss */
    public function dismissDuplicates(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        $data = $request->validate([
            'id' => 'nullable|integer',
        ]);

        return response()->json(
            $this->missedPunches->dismissDuplicatePunches((int) $orgId, isset($data['id']) ? (int) $data['id'] : null)
        );
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
