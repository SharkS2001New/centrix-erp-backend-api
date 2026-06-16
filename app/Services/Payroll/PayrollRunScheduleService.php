<?php

namespace App\Services\Payroll;

use App\Services\Hr\HrPayrollSettingsResolver;
use App\Models\PayPeriod;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class PayrollRunScheduleService
{
    public const GRACE_DAYS_AFTER_MONTH_END = 7;

    public const DELETE_LOCK_MINUTES = 20;

    protected function graceDays(?int $organizationId = null): int
    {
        if (! $organizationId) {
            return self::GRACE_DAYS_AFTER_MONTH_END;
        }

        return (int) HrPayrollSettingsResolver::forOrganizationId($organizationId)['grace_days_after_month_end'];
    }

    protected function deleteLockMinutes(?int $organizationId = null): int
    {
        if (! $organizationId) {
            return self::DELETE_LOCK_MINUTES;
        }

        return (int) HrPayrollSettingsResolver::forOrganizationId($organizationId)['payroll_run_delete_lock_minutes'];
    }

    /**
     * @return array<int, array{period_code: string, period_start: string, period_end: string}>
     */
    public function runnablePeriodSpecs(?Carbon $today = null, ?int $organizationId = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $specs = [];

        if ($this->isInPreviousMonthGraceWindow($today, $organizationId)) {
            $specs[] = $this->monthPeriodSpec($today->copy()->subMonthNoOverflow());
        }

        if ($this->isLastDayOfMonth($today)) {
            $specs[] = $this->monthPeriodSpec($today);
        }

        $codes = [];
        $unique = [];
        foreach ($specs as $spec) {
            if (in_array($spec['period_code'], $codes, true)) {
                continue;
            }
            $codes[] = $spec['period_code'];
            $unique[] = $spec;
        }

        return $unique;
    }

    public function canRunPayrollForPeriod(PayPeriod $period, ?Carbon $today = null): bool
    {
        return $this->canRunPayrollForRange(
            Carbon::parse($period->period_start)->startOfDay(),
            Carbon::parse($period->period_end)->startOfDay(),
            $today,
            (int) $period->organization_id,
        );
    }

    public function canRunPayrollForRange(
        Carbon $periodStart,
        Carbon $periodEnd,
        ?Carbon $today = null,
        ?int $organizationId = null,
    ): bool {
        $today = ($today ?? now())->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();
        $graceDays = $this->graceDays($organizationId);

        $periodYm = (int) $periodEnd->format('Ym');
        $todayYm = (int) $today->format('Ym');

        if ($periodYm > $todayYm) {
            return false;
        }

        if ($periodYm === $todayYm) {
            return $this->isLastDayOfMonth($today);
        }

        $graceStart = $periodEnd->copy()->addMonthNoOverflow()->startOfMonth();

        return $today->gte($graceStart)
            && $today->lte($graceStart->copy()->addDays($graceDays - 1));
    }

    public function assertCanRunPayrollForPeriod(PayPeriod $period, ?Carbon $today = null): void
    {
        if ($this->canRunPayrollForPeriod($period, $today)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $this->runBlockedMessage($period, $today),
        ], 422));
    }

    public function runBlockedMessage(PayPeriod $period, ?Carbon $today = null): string
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $periodEnd = Carbon::parse($period->period_end)->startOfDay();
        $label = $periodEnd->format('F Y');
        $graceDays = $this->graceDays((int) $period->organization_id);

        if ((int) $periodEnd->format('Ym') > (int) $today->format('Ym')) {
            return "Payroll for {$label} cannot run before that month ends. Upcoming months are not allowed.";
        }

        if ((int) $periodEnd->format('Ym') === (int) $today->format('Ym')) {
            return "Payroll for {$label} can only run on the last day of the month ("
                . $periodEnd->format('j M Y')
                . ').';
        }

        $graceEnd = $periodEnd->copy()->addMonthNoOverflow()->startOfMonth()
            ->addDays($graceDays - 1);

        return "Payroll for {$label} can only run from "
            . $periodEnd->format('j M')
            . ' through '
            . $graceEnd->format('j M Y')
            . ' (month end or first week of the following month).';
    }

    /**
     * @return Collection<int, PayPeriod>
     */
    public function ensureRunnablePeriods(int $organizationId, ?Carbon $today = null): Collection
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $periods = collect();

        foreach ($this->runnablePeriodSpecs($today, $organizationId) as $spec) {
            $periods->push(
                PayPeriod::query()->firstOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'period_code' => $spec['period_code'],
                    ],
                    [
                        'period_start' => $spec['period_start'],
                        'period_end' => $spec['period_end'],
                        'status' => 'open',
                    ],
                ),
            );
        }

        return $periods->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(?Carbon $today = null, ?int $organizationId = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $graceDays = $this->graceDays($organizationId);
        $deleteLock = $this->deleteLockMinutes($organizationId);
        $runnable = $this->runnablePeriodSpecs($today, $organizationId);

        return [
            'today' => $today->toDateString(),
            'delete_lock_minutes' => $deleteLock,
            'grace_days_after_month_end' => $graceDays,
            'rules' => [
                'Payroll may run for the current month only on that month\'s last calendar day.',
                'Payroll for the previous month may run during the first '
                    . $graceDays
                    . ' days of the following month.',
                'Upcoming (future) months cannot be processed.',
            ],
            'runnable_period_codes' => array_column($runnable, 'period_code'),
            'runnable_periods' => $runnable,
            'can_run_any_period_today' => count($runnable) > 0,
        ];
    }

    /**
     * @return array{period_code: string, period_start: string, period_end: string}
     */
    public function monthPeriodSpec(Carbon $anyDayInMonth): array
    {
        $start = $anyDayInMonth->copy()->startOfMonth();
        $end = $anyDayInMonth->copy()->endOfMonth();

        return [
            'period_code' => $start->format('Y-m'),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ];
    }

    public function isLastDayOfMonth(Carbon $date): bool
    {
        $date = $date->copy()->startOfDay();

        return $date->day === $date->daysInMonth;
    }

    public function isInPreviousMonthGraceWindow(Carbon $today, ?int $organizationId = null): bool
    {
        $today = $today->copy()->startOfDay();

        return $today->day <= $this->graceDays($organizationId);
    }

    public function canDeletePayrollRun(?Carbon $createdAt, ?Carbon $runDate = null, ?int $organizationId = null): bool
    {
        if (! $createdAt) {
            return false;
        }

        return now()->lt($createdAt->copy()->addMinutes($this->deleteLockMinutes($organizationId)));
    }

    public function deleteLockExpiresAt(?Carbon $createdAt, ?Carbon $runDate = null, ?int $organizationId = null): Carbon
    {
        if ($createdAt) {
            return $createdAt->copy()->addMinutes($this->deleteLockMinutes($organizationId));
        }

        return ($runDate?->copy()->startOfDay() ?? now())->addMinutes($this->deleteLockMinutes($organizationId));
    }
}
