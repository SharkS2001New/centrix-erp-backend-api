<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\MobileRepAttendanceSession;
use App\Models\User;
use App\Services\Attendance\FieldRepHrLinkageService;
use App\Support\AppTimezone;
use Illuminate\Support\Facades\Schema;

class FieldRepAttendanceHrSync
{
    public function __construct(
        protected MobileFieldAttendanceService $fieldAttendance,
        protected AttendanceDayPolicy $dayPolicy,
        protected FieldRepHrLinkageService $linkage,
    ) {}

    public function syncSession(MobileRepAttendanceSession $session): ?EmployeeAttendance
    {
        if (! $session->sign_in_at) {
            return null;
        }

        $signIn = AppTimezone::normalize($session->sign_in_at);
        if (! $signIn) {
            return null;
        }

        return $this->syncUserDay((int) $session->user_id, $signIn->toDateString());
    }

    public function syncUserDay(int $userId, string $attendanceDate): ?EmployeeAttendance
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return null;
        }

        $employee = $this->linkage->activeEmployeeForUser($user);
        if (! $employee) {
            return null;
        }

        $existing = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $attendanceDate)
            ->first();

        if ($existing) {
            if ($existing->payroll_run_id !== null) {
                return $existing;
            }

            if ($existing->source !== 'field_rep') {
                return $existing;
            }
        }

        $sessions = MobileRepAttendanceSession::query()
            ->where('user_id', $userId)
            ->whereDate('sign_in_at', $attendanceDate)
            ->orderBy('sign_in_at')
            ->get();

        if ($sessions->isEmpty()) {
            return null;
        }

        $totalWorkSeconds = 0;
        $firstSignIn = null;
        $lastSignOut = null;
        $hasCompletedSession = false;

        foreach ($sessions as $session) {
            $totalWorkSeconds += $this->fieldAttendance->workSeconds($session);

            $signIn = AppTimezone::normalize($session->sign_in_at);
            if ($signIn && ($firstSignIn === null || $signIn->lt($firstSignIn))) {
                $firstSignIn = $signIn;
            }

            if ($session->sign_out_at) {
                $hasCompletedSession = true;
                $signOut = AppTimezone::normalize($session->sign_out_at);
                if ($signOut && ($lastSignOut === null || $signOut->gt($lastSignOut))) {
                    $lastSignOut = $signOut;
                }
            }
        }

        if (! $hasCompletedSession) {
            return $existing;
        }

        $eval = $this->dayPolicy->evaluate($employee, $attendanceDate);
        $status = $eval['should_work']
            ? ($totalWorkSeconds > 0 ? 'present' : 'absent')
            : $eval['suggested_status'];

        $checkIn = $firstSignIn?->format('H:i:s');
        $checkOut = $lastSignOut?->format('H:i:s');
        $hoursWorked = $status === 'present'
            ? round($totalWorkSeconds / 3600, 2)
            : 0;

        $attendance = EmployeeAttendance::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_date' => $attendanceDate,
            ],
            [
                'organization_id' => $employee->organization_id,
                'branch_id' => $employee->branch_id,
                'check_in' => $status === 'present' ? $checkIn : null,
                'check_out' => $status === 'present' ? $checkOut : null,
                'status' => $status,
                'source' => 'field_rep',
                'device_identifier' => $sessions->last()?->device_identifier,
                'hours_worked' => $hoursWorked,
                'notes' => $eval['reason'],
            ],
        );

        if (Schema::hasColumn('mobile_rep_attendance_sessions', 'attendance_id')) {
            MobileRepAttendanceSession::query()
                ->whereIn('id', $sessions->pluck('id'))
                ->update(['attendance_id' => $attendance->id]);
        }

        return $attendance;
    }
}
