<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeClockSession;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Support\AttendanceHours;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceClockController extends Controller
{
    /** POST /attendance/clock-in */
    public function clockIn(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'device_identifier' => 'nullable|string|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $employee = Employee::with('shift')->findOrFail($data['employee_id']);
        $orgId = $request->user()?->organization_id;
        if ($orgId && (int) $employee->organization_id !== (int) $orgId) {
            return response()->json(['message' => 'Employee not in your organization.'], 403);
        }

        try {
            app(AttendanceDayPolicy::class)->assertCanClockIn($employee);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $open = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->whereNull('clock_out_at')
            ->first();

        if ($open) {
            return response()->json(['message' => 'Employee already has an open clock-in session.'], 422);
        }

        $now = now();
        $session = EmployeeClockSession::create([
            'employee_id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'branch_id' => $data['branch_id'] ?? $employee->branch_id,
            'clock_in_at' => $now,
            'device_identifier' => $data['device_identifier'] ?? null,
        ]);

        return response()->json($session->load('employee'), 201);
    }

    /** POST /attendance/clock-out */
    public function clockOut(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'device_identifier' => 'nullable|string|max:100',
        ]);

        $employee = Employee::with('shift')->findOrFail($data['employee_id']);
        $orgId = $request->user()?->organization_id;
        if ($orgId && (int) $employee->organization_id !== (int) $orgId) {
            return response()->json(['message' => 'Employee not in your organization.'], 403);
        }

        $session = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->whereNull('clock_out_at')
            ->orderByDesc('clock_in_at')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'No open clock-in session for this employee.'], 422);
        }

        $out = now();
        $session->clock_out_at = $out;
        if (! empty($data['device_identifier'])) {
            $session->device_identifier = $data['device_identifier'];
        }

        $in = Carbon::parse($session->clock_in_at);
        $attendanceDate = $in->toDateString();
        $checkIn = $in->format('H:i:s');
        $checkOut = $out->format('H:i:s');
        $hours = AttendanceHours::fromTimeStrings($checkIn, $checkOut);
        $policy = app(AttendanceDayPolicy::class);
        $eval = $policy->evaluate($employee, $attendanceDate);
        $status = $eval['should_work'] ? 'present' : $eval['suggested_status'];

        $attendance = EmployeeAttendance::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_date' => $attendanceDate,
            ],
            [
                'organization_id' => $employee->organization_id,
                'branch_id' => $session->branch_id ?? $employee->branch_id,
                'check_in' => $status === 'present' ? $checkIn : null,
                'check_out' => $status === 'present' ? $checkOut : null,
                'status' => $status,
                'source' => 'clock_device',
                'device_identifier' => $session->device_identifier,
                'hours_worked' => $status === 'present' ? $hours : 0,
                'notes' => $eval['reason'],
            ],
        );

        $session->attendance_id = $attendance->id;
        $session->save();

        return response()->json([
            'session' => $session->load(['employee', 'attendance']),
            'attendance' => $attendance,
        ]);
    }

    /** GET /attendance/clock-sessions — open and recent sessions */
    public function sessions(Request $request)
    {
        $query = EmployeeClockSession::query()->with('employee')->orderByDesc('clock_in_at');
        $orgId = $request->user()?->organization_id;
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }
        if ($request->boolean('open_only')) {
            $query->whereNull('clock_out_at');
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }
}
