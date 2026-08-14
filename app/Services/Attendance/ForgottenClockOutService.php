<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Services\Payroll\PayrollCycleSettlementService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * After the overnight punch window (02:00 Nairobi), close yesterday's open
 * sessions at scheduled shift end so hours exist for payroll, and flag them
 * for HR on Missed punches → Forgotten clock-outs.
 */
class ForgottenClockOutService
{
    public const AUTO_NOTE = 'Forgotten clock-out: auto-closed at shift end';

    public function __construct(
        protected AttendanceDayReconciler $reconciler,
    ) {
    }

    /**
     * @return array{closed: int, skipped: int, errors: list<string>}
     */
    public function closeDueSessions(?int $organizationId = null): array
    {
        $todayStart = AppTimezone::parseDateStart(AppTimezone::todayDateString());
        $query = EmployeeClockSession::query()
            ->with(['employee.shift', 'attendance'])
            ->whereNull('clock_out_at')
            ->whereIn('source', ['clock_device', 'company_mobile'])
            ->where('clock_in_at', '<', $todayStart->format('Y-m-d H:i:s'));

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $closed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($query->orderBy('id')->limit(500)->get() as $session) {
            try {
                if ($this->closeOpenSession($session)) {
                    $closed++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = 'session '.$session->id.': '.$e->getMessage();
            }
        }

        return [
            'closed' => $closed,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    public function suggestedCloseAt(EmployeeClockSession $session): ?Carbon
    {
        $in = AppTimezone::normalize($session->clock_in_at)
            ?? AppTimezone::fromDeviceWallClock($session->clock_in_at);
        if (! $in) {
            return null;
        }

        $employee = $session->employee;
        $date = $in->copy()->timezone(AppTimezone::name())->toDateString();
        $end = '17:00:00';
        $crosses = false;
        if ($employee instanceof Employee) {
            $employee->loadMissing('shift');
            $hours = $employee->shift?->hoursForDate($date) ?? null;
            if (! empty($hours['end_time'])) {
                $end = strlen((string) $hours['end_time']) === 5
                    ? $hours['end_time'].':00'
                    : (string) $hours['end_time'];
                $crosses = (bool) ($hours['crosses_midnight'] ?? false);
            }
        }

        $closeAt = Carbon::parse($date.' '.$end, AppTimezone::name());
        if ($crosses) {
            $closeAt->addDay();
        }
        if ($closeAt->lte($in)) {
            $closeAt = $in->copy()->addMinutes(1);
        }

        return $closeAt;
    }

    public function closeOpenSession(EmployeeClockSession $session): bool
    {
        if ($session->clock_out_at) {
            return false;
        }

        $employee = $session->employee;
        if (! $employee) {
            return false;
        }

        PayrollCycleSettlementService::assertNotPayrollLocked(
            $session->attendance?->payroll_run_id,
            'attendance punch',
        );

        $closeAt = $this->suggestedCloseAt($session);
        if ($closeAt === null) {
            return false;
        }

        $now = AppTimezone::now();
        if ($closeAt->gt($now)) {
            return false;
        }

        $session->clock_out_at = $closeAt;
        $session->clock_out_kind = EmployeeClockSession::CLOCK_OUT_KIND_AUTO_FORGOTTEN;
        $session->needs_reconciliation = true;
        $session->save();

        $date = AppTimezone::normalize($session->clock_in_at)?->toDateString()
            ?? $closeAt->toDateString();
        $attendance = $this->reconciler->reconcileFromSessions(
            $employee,
            $date,
            (string) ($session->source ?: 'clock_device'),
            $session->device_identifier,
            $session->branch_id ? (int) $session->branch_id : null,
        );

        $note = self::AUTO_NOTE.' ('.$closeAt->format('H:i').'). Confirm on Missed punches → Forgotten clock-outs.';
        $existing = trim((string) ($attendance->notes ?? ''));
        if (! str_contains($existing, self::AUTO_NOTE)) {
            $attendance->notes = trim($existing !== '' ? $existing.' '.$note : $note);
            $attendance->save();
        }

        $session->attendance_id = $attendance->id;
        $session->save();

        return true;
    }
}
