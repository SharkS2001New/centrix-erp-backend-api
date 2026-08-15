<?php

namespace App\Services\Attendance;

use App\Models\EmployeeAttendance;
use App\Models\MobileRepAttendanceSession;
use App\Models\User;
use App\Services\Sales\MobileFieldAttendanceService;
use App\Support\AppTimezone;
use Illuminate\Support\Facades\Schema;

class FieldRepAttendanceHrSync
{
    public function __construct(
        protected MobileFieldAttendanceService $fieldAttendance,
        protected AttendanceDayPolicy $dayPolicy,
        protected AttendanceDayReconciler $reconciler,
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

        $employee->loadMissing('shift');

        $existing = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $attendanceDate)
            ->first();

        if ($this->shouldPreserveExisting($existing)) {
            return $existing;
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

        if (! $firstSignIn) {
            return $existing;
        }

        $deviceIdentifier = $sessions->last()?->device_identifier;
        $branchId = $employee->branch_id;

        if (! $hasCompletedSession || ! $lastSignOut) {
            $attendance = $this->reconciler->recordOpenClockIn(
                $employee,
                $firstSignIn,
                'field_rep',
                $deviceIdentifier,
                $branchId,
                true,
            );

            if ($attendance->source === 'field_rep') {
                $attendance->check_out = null;
                $attendance->hours_worked = 0;
                $attendance->notes = 'On route — awaiting sign-out';
                $attendance->save();
            }

            $this->linkSessions($sessions, $attendance);

            return $attendance;
        }

        $eval = $this->dayPolicy->evaluate($employee, $attendanceDate);
        $status = $eval['should_work']
            ? ($totalWorkSeconds > 0 ? 'present' : 'absent')
            : $eval['suggested_status'];

        $attendance = $this->reconciler->reconcileManualSpan(
            $employee,
            $attendanceDate,
            $firstSignIn->format('H:i:s'),
            $lastSignOut->format('H:i:s'),
            'field_rep',
            $deviceIdentifier,
            $branchId,
            $eval['reason'] ?? null,
            $status,
            true,
        );

        $hoursWorked = $status === 'present' || $status === 'late' || $status === 'half_day'
            ? round($totalWorkSeconds / 3600, 2)
            : 0;
        $expected = (float) ($attendance->expected_hours ?? 0);
        if ($expected > 0 && $hoursWorked > $expected) {
            $hoursWorked = $expected;
        }
        $attendance->hours_worked = $hoursWorked;
        $attendance->source = 'field_rep';
        $attendance->save();

        $this->linkSessions($sessions, $attendance);

        return $attendance;
    }

    protected function shouldPreserveExisting(?EmployeeAttendance $existing): bool
    {
        if (! $existing) {
            return false;
        }

        if ($existing->payroll_run_id !== null) {
            return true;
        }

        if ($existing->source === 'field_rep') {
            return false;
        }

        if (in_array((string) $existing->status, ['absent', 'leave', 'holiday'], true)) {
            return false;
        }

        return (bool) $existing->check_in;
    }

    protected function linkSessions($sessions, EmployeeAttendance $attendance): void
    {
        if (! Schema::hasColumn('mobile_rep_attendance_sessions', 'attendance_id')) {
            return;
        }

        MobileRepAttendanceSession::query()
            ->whereIn('id', $sessions->pluck('id'))
            ->update(['attendance_id' => $attendance->id]);
    }
}
