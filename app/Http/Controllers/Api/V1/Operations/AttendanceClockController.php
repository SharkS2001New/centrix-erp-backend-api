<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\EmployeeClockSession;
use App\Services\Attendance\AttendanceClockPunchService;
use App\Support\AppTimezone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceClockController extends Controller
{
    public function __construct(
        protected AttendanceClockPunchService $punchService,
    ) {
    }

    /**
     * POST /attendance/clock-punch
     * Unified ingest for fingerprint terminals (Hikvision bridge, middleware, etc.).
     */
    public function punch(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
            'employee_code' => 'nullable|string|max:50',
            'device_no' => 'nullable|string|max:100',
            'device_identifier' => 'nullable|string|max:100',
            'punched_at' => 'nullable|date',
            'direction' => 'nullable|in:auto,in,out',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        if (empty($data['employee_id']) && empty($data['employee_code'])) {
            throw ValidationException::withMessages([
                'employee_code' => 'Provide employee_code (terminal person ID) or employee_id.',
            ]);
        }

        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        $result = $this->punchService->punch([
            'organization_id' => (int) $orgId,
            ...$data,
        ]);

        $status = $result['action'] === 'in' ? 201 : 200;

        return response()->json($result, $status);
    }

    /** POST /attendance/clock-in */
    public function clockIn(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
            'employee_code' => 'nullable|string|max:50',
            'device_identifier' => 'nullable|string|max:100',
            'device_no' => 'nullable|string|max:100',
            'punched_at' => 'nullable|date',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        $result = $this->punchService->punch([
            'organization_id' => (int) $orgId,
            'direction' => 'in',
            ...$data,
        ]);

        return response()->json($result['session'], 201);
    }

    /** POST /attendance/clock-out */
    public function clockOut(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
            'employee_code' => 'nullable|string|max:50',
            'device_identifier' => 'nullable|string|max:100',
            'device_no' => 'nullable|string|max:100',
            'punched_at' => 'nullable|date',
        ]);

        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        $result = $this->punchService->punch([
            'organization_id' => (int) $orgId,
            'direction' => 'out',
            ...$data,
        ]);

        return response()->json([
            'session' => $result['session'],
            'attendance' => $result['attendance'] ?? null,
        ]);
    }

    /** GET /attendance/clock-sessions — open and recent sessions */
    public function sessions(Request $request)
    {
        $query = EmployeeClockSession::query()->with(['employee.shift'])->orderByDesc('clock_in_at');
        $orgId = $request->user()?->organization_id;
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }
        if ($request->boolean('open_only')) {
            $query->whereNull('clock_out_at');
        }
        if ($request->boolean('today')) {
            $start = AppTimezone::parseDateStart(AppTimezone::todayDateString());
            $end = AppTimezone::parseDateEnd(AppTimezone::todayDateString());
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('clock_in_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->whereNotNull('clock_out_at')
                            ->whereBetween('clock_out_at', [$start, $end]);
                    });
            });
        }
        if ($request->boolean('premises') || $request->boolean('today')) {
            $query->whereIn('source', ['clock_device', 'company_mobile']);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    /** DELETE /attendance/clock-sessions/{session} */
    public function destroySession(Request $request, int $session)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization context required.'], 403);
        }

        $this->punchService->deleteSession((int) $orgId, $session);

        return response()->json(null, 204);
    }
}
