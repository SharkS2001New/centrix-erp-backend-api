<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Map clock sessions for a day onto Clock in / Lunch out / Lunch in / Clock out.
 */
class AttendanceDayPunchPresenter
{
    public function __construct(
        protected AttendancePunchWindowResolver $windows,
    ) {
    }

    /**
     * @param  Collection<int, EmployeeClockSession>|iterable<EmployeeClockSession>  $sessions
     * @return array{
     *   clock_in: ?string,
     *   lunch_out: ?string,
     *   lunch_in: ?string,
     *   clock_out: ?string,
     *   lunch_required: bool,
     *   session_ids: list<int>
     * }
     */
    public function present(Employee $employee, string $date, iterable $sessions): array
    {
        $at = Carbon::parse($date.' 12:00:00', AppTimezone::name());
        $windows = $this->windows->windowsFor($employee, $at);
        $lunchRequired = $this->lunchRequired($windows);

        $sorted = Collection::make($sessions)
            ->sortBy(fn (EmployeeClockSession $s) => optional($s->clock_in_at)?->timestamp ?? 0)
            ->values();

        $empty = [
            'clock_in' => null,
            'lunch_out' => null,
            'lunch_in' => null,
            'clock_out' => null,
            'lunch_required' => $lunchRequired,
            'session_ids' => $sorted->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];

        if ($sorted->isEmpty()) {
            return $empty;
        }

        $first = $sorted->first();
        $second = $sorted->get(1);
        $last = $sorted->last();

        $empty['clock_in'] = $this->clockHm($first->clock_in_at);

        if (! $lunchRequired) {
            $empty['clock_out'] = $this->resolveFinalClockOut($sorted);

            return $empty;
        }

        // True lunch break: morning session closed in the lunch window, then a return session.
        // Never invent lunch from auto-forgotten shift-end closes or evening outs.
        if ($second && $first->clock_out_at && $this->looksLikeLunchBreak($first, $second, $windows)) {
            $empty['lunch_out'] = $this->clockHm($first->clock_out_at);
            $empty['lunch_in'] = $this->clockHm($second->clock_in_at);
            $empty['clock_out'] = $this->clockHm($last->clock_out_at);

            return $empty;
        }

        $empty['clock_out'] = $this->resolveFinalClockOut($sorted);
        if ($empty['clock_out'] !== null) {
            return $empty;
        }

        $outKind = $this->classifyLoneOut($first->clock_out_at, $windows);
        if ($outKind === 'lunch_out') {
            $empty['lunch_out'] = $this->clockHm($first->clock_out_at);
        } elseif ($outKind === 'clock_out') {
            $empty['clock_out'] = $this->clockHm($first->clock_out_at);
        }

        return $empty;
    }

    /**
     * @param  Collection<int, EmployeeClockSession>  $sorted
     */
    protected function resolveFinalClockOut(Collection $sorted): ?string
    {
        $lastClosed = $sorted->last(fn (EmployeeClockSession $s) => $s->clock_out_at !== null);
        if ($lastClosed && ! $this->isAutoForgotten($lastClosed)) {
            return $this->clockHm($lastClosed->clock_out_at);
        }

        // After an auto-forgotten close, a later open session's clock-in is the real clock-out.
        $orphanedIn = $sorted->first(function (EmployeeClockSession $s) use ($lastClosed) {
            if ($s->clock_out_at !== null || ! $s->clock_in_at || ! $lastClosed?->clock_out_at) {
                return false;
            }
            if (! $this->isAutoForgotten($lastClosed)) {
                return false;
            }

            return AppTimezone::normalize($s->clock_in_at)?->gt(AppTimezone::normalize($lastClosed->clock_out_at));
        });
        if ($orphanedIn) {
            return $this->clockHm($orphanedIn->clock_in_at);
        }

        if ($lastClosed) {
            return $this->clockHm($lastClosed->clock_out_at);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $windows
     */
    protected function looksLikeLunchBreak(
        EmployeeClockSession $first,
        EmployeeClockSession $second,
        array $windows,
    ): bool {
        if ($this->isAutoForgotten($first)) {
            return false;
        }

        if ($this->classifyLoneOut($first->clock_out_at, $windows) !== 'lunch_out') {
            return false;
        }

        $outAt = AppTimezone::normalize($first->clock_out_at);
        $inAt = AppTimezone::normalize($second->clock_in_at);
        if (! $outAt || ! $inAt || ! $inAt->gt($outAt)) {
            return false;
        }

        // Lunch return should land before a typical evening clock-out window starts.
        $eveningFrom = $this->toMinutes($windows['evening_clock_out_from'] ?? null);
        if ($eveningFrom !== null) {
            $inMinutes = ($inAt->hour * 60) + $inAt->minute;
            if ($inMinutes >= $eveningFrom) {
                return false;
            }
        }

        // Gap longer than 4 hours is not a lunch break (forgotten close + late punch).
        $gapMinutes = (int) floor(($inAt->getTimestamp() - $outAt->getTimestamp()) / 60);
        if ($gapMinutes > 240) {
            return false;
        }

        return true;
    }

    protected function isAutoForgotten(EmployeeClockSession $session): bool
    {
        return ($session->clock_out_kind ?? null) === EmployeeClockSession::CLOCK_OUT_KIND_AUTO_FORGOTTEN;
    }

    /**
     * @param  array<string, mixed>  $windows
     */
    protected function lunchRequired(array $windows): bool
    {
        $from = trim((string) ($windows['lunch_clock_out_from'] ?? ''));
        $to = trim((string) ($windows['lunch_clock_out_to'] ?? ''));

        return $from !== '' && $to !== '';
    }

    /**
     * @param  array<string, mixed>  $windows
     */
    protected function classifyLoneOut(mixed $clockOutAt, array $windows): ?string
    {
        $out = AppTimezone::normalize($clockOutAt);
        if (! $out) {
            return null;
        }

        $minutes = ($out->hour * 60) + $out->minute;
        $lunchFrom = $this->toMinutes($windows['lunch_clock_out_from'] ?? null);
        $lunchTo = $this->toMinutes($windows['lunch_clock_out_to'] ?? null);
        $eveningFrom = $this->toMinutes($windows['evening_clock_out_from'] ?? null);
        $eveningTo = $this->toMinutes($windows['evening_clock_out_to'] ?? null);

        if ($eveningFrom !== null && $this->inRange($minutes, $eveningFrom, $eveningTo ?? ((24 * 60) - 1))) {
            return 'clock_out';
        }
        if ($lunchFrom !== null && $lunchTo !== null && $this->inRange($minutes, $lunchFrom, $lunchTo)) {
            return 'lunch_out';
        }
        if ($lunchFrom !== null && $minutes < $lunchFrom) {
            return 'clock_out';
        }

        return 'lunch_out';
    }

    protected function clockHm(mixed $value): ?string
    {
        $time = AppTimezone::clockTime($value);
        if ($time === null || $time === '') {
            return null;
        }

        return substr($time, 0, 5);
    }

    protected function toMinutes(mixed $value): ?int
    {
        $text = HrAttendanceSettingsResolver::normalizeClockTime($value, '');
        if ($text === '' || ! preg_match('/^(\d{2}):(\d{2})$/', $text, $m)) {
            return null;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }

    protected function inRange(int $minutes, int $from, int $to): bool
    {
        if ($to < $from) {
            return $minutes >= $from || $minutes <= $to;
        }

        return $minutes >= $from && $minutes <= $to;
    }
}
