<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\InAppNotificationService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Detect absurd attendance (out before in, tiny worked spans) and alert HR.
 * Prefers a short Centrix AI message; falls back to a clear heuristic text.
 */
class AttendanceAnomalyAlertService
{
    public const TYPE = 'attendance_anomaly';

    /** Worked span shorter than this (with a clock-out) is treated as absurd. */
    public const MIN_PLAUSIBLE_WORK_MINUTES = 15;

    public function __construct(
        protected InAppNotificationService $notifications,
        protected UserPermissionService $permissions,
        protected AiProviderFactory $aiProviders,
    ) {}

    /**
     * @param  list<array{in: Carbon, out: Carbon}>  $pairs
     */
    public function evaluateAfterReconcile(
        Employee $employee,
        string $date,
        array $pairs,
        EmployeeAttendance $attendance,
    ): void {
        $flags = $this->detectFlags($pairs, $attendance);
        if ($flags === []) {
            return;
        }

        $actionUrl = '/hr/attendance/history?'.http_build_query([
            'from_date' => $date,
            'to_date' => $date,
            'employee_id' => (int) $employee->id,
        ]);

        if ($this->alreadyNotified((int) $employee->organization_id, $actionUrl, $date, (int) $employee->id)) {
            return;
        }

        $employee->loadMissing('organization');
        $name = trim((string) ($employee->full_name ?: trim(($employee->first_name ?? '').' '.($employee->last_name ?? ''))));
        if ($name === '') {
            $name = 'Employee #'.$employee->id;
        }

        $facts = $this->factsLine($employee, $date, $pairs, $attendance, $flags);
        $message = $this->composeMessage($employee->organization, $name, $date, $facts, $flags);
        $title = 'Attendance needs review: '.$name;

        $recipients = $this->hrRecipients((int) $employee->organization_id);
        if ($recipients->isEmpty()) {
            Log::info('attendance_anomaly.no_recipients', [
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'date' => $date,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            $this->notifications->createForUser($recipient, [
                'organization_id' => (int) $employee->organization_id,
                'type' => self::TYPE,
                'severity' => 'warning',
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
            ]);
        }
    }

    /**
     * @param  list<array{in: Carbon, out: Carbon}>  $pairs
     * @return list<string>
     */
    public function detectFlags(array $pairs, EmployeeAttendance $attendance): array
    {
        $flags = [];

        foreach ($pairs as $pair) {
            $in = $pair['in']->copy()->timezone(AppTimezone::name());
            $out = $pair['out']->copy()->timezone(AppTimezone::name());
            if ($out->lte($in)) {
                $flags[] = 'clock_out_before_clock_in';
                break;
            }
        }

        $workMinutes = 0;
        foreach ($pairs as $pair) {
            $in = $pair['in'];
            $out = $pair['out'];
            if ($out->gt($in)) {
                $workMinutes += (int) floor(($out->getTimestamp() - $in->getTimestamp()) / 60);
            }
        }

        $hasClosedPair = $pairs !== [];
        if (
            $hasClosedPair
            && $workMinutes >= 0
            && $workMinutes < self::MIN_PLAUSIBLE_WORK_MINUTES
            && in_array((string) $attendance->status, ['present', 'late', 'half_day'], true)
        ) {
            $flags[] = 'implausibly_short_work';
        }

        $checkIn = AppTimezone::clockTime($attendance->check_in);
        $checkOut = AppTimezone::clockTime($attendance->check_out);
        if (is_string($checkIn) && is_string($checkOut) && $checkIn !== '' && $checkOut !== '') {
            $inMin = $this->hmToMinutes(substr($checkIn, 0, 5));
            $outMin = $this->hmToMinutes(substr($checkOut, 0, 5));
            if ($inMin !== null && $outMin !== null && $outMin < $inMin) {
                if (! in_array('clock_out_before_clock_in', $flags, true)) {
                    $flags[] = 'clock_out_before_clock_in';
                }
            }
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  list<array{in: Carbon, out: Carbon}>  $pairs
     * @param  list<string>  $flags
     */
    protected function factsLine(
        Employee $employee,
        string $date,
        array $pairs,
        EmployeeAttendance $attendance,
        array $flags,
    ): string {
        $parts = [
            'Employee: '.($employee->full_name ?: '#'.$employee->id),
            'Date: '.$date,
            'Status: '.(string) $attendance->status,
            'Recorded check-in: '.(AppTimezone::clockTime($attendance->check_in) ?: '—'),
            'Recorded check-out: '.(AppTimezone::clockTime($attendance->check_out) ?: '—'),
            'Hours worked: '.number_format((float) ($attendance->hours_worked ?? 0), 2),
            'Late minutes: '.(int) ($attendance->late_minutes ?? 0),
            'Flags: '.implode(', ', $flags),
        ];

        foreach ($pairs as $i => $pair) {
            $in = $pair['in']->copy()->timezone(AppTimezone::name())->format('H:i');
            $out = $pair['out']->copy()->timezone(AppTimezone::name())->format('H:i');
            $parts[] = 'Session '.($i + 1).": {$in} → {$out}";
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<string>  $flags
     */
    protected function composeMessage(
        ?Organization $organization,
        string $employeeName,
        string $date,
        string $facts,
        array $flags,
    ): string {
        $fallback = $this->heuristicMessage($employeeName, $date, $flags, $facts);
        if (! $organization) {
            return $fallback;
        }

        $runtime = AiSettingsResolver::resolveRuntimeForOrganization($organization);
        if (! $runtime || empty($runtime['api_key'])) {
            return $fallback;
        }

        try {
            $turn = $this->aiProviders->make($runtime)->chat([
                'system' => 'You write brief HR attendance alerts for Centrix ERP. '
                    .'Return plain text only (2–4 short sentences). '
                    .'Explain what looks wrong, ask HR to verify the punches on Previous Attendance, '
                    .'and do not invent times beyond the facts given.',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "Write an HR alert for this anomalous attendance:\n\n{$facts}",
                    ],
                ],
                'temperature' => 0.2,
                'max_output_tokens' => 220,
            ]);
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text !== '') {
                return mb_substr($text, 0, 1200);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $fallback;
    }

    /**
     * @param  list<string>  $flags
     */
    protected function heuristicMessage(string $employeeName, string $date, array $flags, string $facts): string
    {
        $reasons = [];
        if (in_array('clock_out_before_clock_in', $flags, true)) {
            $reasons[] = 'clock-out is earlier than clock-in (or the session span is inverted)';
        }
        if (in_array('implausibly_short_work', $flags, true)) {
            $reasons[] = 'worked time is under '.self::MIN_PLAUSIBLE_WORK_MINUTES.' minutes — too short for a real shift';
        }

        $why = $reasons !== [] ? implode('; ', $reasons) : 'punch times look inconsistent';

        return "Please review {$employeeName}'s attendance for {$date}: {$why}. "
            ."Open Previous Attendance for that day and correct or clear the device punches if needed.\n\n"
            .$facts;
    }

    protected function alreadyNotified(int $organizationId, string $actionUrl, string $date, int $employeeId): bool
    {
        return InAppNotification::query()
            ->where('organization_id', $organizationId)
            ->where('type', self::TYPE)
            ->where('action_url', $actionUrl)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function hrRecipients(int $organizationId)
    {
        $recipients = $this->permissions
            ->usersWithPermission($organizationId, 'hr.attendance.view')
            ->filter(fn (User $u) => (bool) $u->is_active)
            ->values();

        if ($recipients->isEmpty()) {
            $recipients = $this->permissions
                ->usersWithPermission($organizationId, 'hr.manage')
                ->filter(fn (User $u) => (bool) $u->is_active)
                ->values();
        }

        if ($recipients->isEmpty()) {
            $recipients = User::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->where('is_admin', true)
                ->whereNull('deleted_at')
                ->get();
        }

        return $recipients;
    }

    protected function hmToMinutes(string $hm): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $hm, $m)) {
            return null;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }
}
