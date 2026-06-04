<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeLeaveDay;
use App\Models\OrganizationLeaveSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveBalanceService
{
    /** @return array{start: Carbon, end: Carbon} */
    public function leavePeriod(Employee $employee): array
    {
        if (! $employee->hire_date) {
            $now = Carbon::now()->startOfDay();

            return [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
            ];
        }

        $hire = Carbon::parse($employee->hire_date)->startOfDay();
        $now = Carbon::now()->startOfDay();
        $start = $hire->copy();

        while ($start->copy()->addYear()->lte($now)) {
            $start->addYear();
        }

        return [
            'start' => $start,
            'end' => $start->copy()->addYear()->subDay(),
        ];
    }

    public function monthsOfService(Employee $employee): int
    {
        if (! $employee->hire_date) {
            return 0;
        }

        return (int) Carbon::parse($employee->hire_date)->startOfDay()->diffInMonths(Carbon::now());
    }

    public function settingsFor(Employee $employee): OrganizationLeaveSettings
    {
        return OrganizationLeaveSettings::forOrganization((int) $employee->organization_id);
    }

    public function entitled(Employee $employee, string $pool): float
    {
        $balance = EmployeeLeaveBalance::forEmployee($employee);

        return match ($pool) {
            'annual' => $this->systemAnnualEntitled($employee) + (float) $balance->annual_adjustment,
            'sick' => $this->systemSickEntitled($employee) + (float) $balance->sick_adjustment,
            'off_days' => (float) $balance->off_days_allocated,
            default => 0.0,
        };
    }

    public function systemAnnualEntitled(Employee $employee): float
    {
        $settings = $this->settingsFor($employee);
        $months = $this->monthsOfService($employee);

        if (! $employee->hire_date || $months <= 0) {
            return 0.0;
        }

        $fullMonths = (int) $settings->months_for_full_annual;
        if ($months < $fullMonths) {
            return min(
                (float) $settings->annual_leave_days,
                round($months * (float) $settings->monthly_accrual_days, 2),
            );
        }

        return (float) $settings->annual_leave_days;
    }

    public function systemSickEntitled(Employee $employee): float
    {
        $settings = $this->settingsFor($employee);
        $months = $this->monthsOfService($employee);

        if ($months < (int) $settings->months_before_sick_eligibility) {
            return 0.0;
        }

        return (float) $settings->sick_leave_days;
    }

    public function used(Employee $employee, string $pool, ?int $exceptLeaveId = null): float
    {
        if ($pool === 'unpaid') {
            return 0.0;
        }

        $query = EmployeeLeaveDay::query()
            ->where('employee_id', $employee->id)
            ->where('deduct_from', $pool);

        if ($pool !== 'off_days') {
            $period = $this->leavePeriod($employee);
            $query
                ->whereDate('start_date', '<=', $period['end']->toDateString())
                ->whereDate('end_date', '>=', $period['start']->toDateString());
        }

        if ($exceptLeaveId) {
            $query->where('id', '!=', $exceptLeaveId);
        }

        return (float) $query->sum(
            DB::raw('COALESCE(NULLIF(days_deducted, 0), total_days, 0)'),
        );
    }

    public function available(Employee $employee, string $pool, ?int $exceptLeaveId = null): float
    {
        if ($pool === 'unpaid') {
            return PHP_FLOAT_MAX;
        }

        $available = $this->entitled($employee, $pool) - $this->used($employee, $pool, $exceptLeaveId);

        return max(0, round($available, 2));
    }

    /** @return array<string, array{entitled: float, used: float, available: float}> */
    public function summary(Employee $employee, ?int $exceptLeaveId = null): array
    {
        $pools = ['annual', 'sick', 'off_days'];
        $out = [];
        foreach ($pools as $pool) {
            $out[$pool] = [
                'entitled' => round($this->entitled($employee, $pool), 2),
                'used' => round($this->used($employee, $pool, $exceptLeaveId), 2),
                'available' => $this->available($employee, $pool, $exceptLeaveId),
            ];
        }

        return $out;
    }

    /** @param array{annual_entitled?: float, sick_entitled?: float, off_days_allocated?: float, notes?: string|null} $data */
    public function applyAdminEntitlements(Employee $employee, array $data): EmployeeLeaveBalance
    {
        $balance = EmployeeLeaveBalance::forEmployee($employee);

        if (array_key_exists('annual_entitled', $data)) {
            $balance->annual_adjustment = round(
                (float) $data['annual_entitled'] - $this->systemAnnualEntitled($employee),
                2,
            );
        }

        if (array_key_exists('sick_entitled', $data)) {
            $balance->sick_adjustment = round(
                (float) $data['sick_entitled'] - $this->systemSickEntitled($employee),
                2,
            );
        }

        if (array_key_exists('off_days_allocated', $data)) {
            $balance->off_days_allocated = max(0, round((float) $data['off_days_allocated'], 2));
        }

        if (array_key_exists('notes', $data)) {
            $balance->notes = $data['notes'] !== null && $data['notes'] !== ''
                ? trim((string) $data['notes'])
                : null;
        }

        $balance->save();

        return $balance->fresh();
    }

    public function assertCanDeduct(
        Employee $employee,
        string $deductFrom,
        float $days,
        ?int $exceptLeaveId = null,
    ): void {
        if ($deductFrom === 'unpaid' || $days <= 0) {
            return;
        }

        $available = $this->available($employee, $deductFrom, $exceptLeaveId);
        if ($available < $days) {
            $label = match ($deductFrom) {
                'annual' => 'annual leave',
                'sick' => 'sick leave',
                'off_days' => 'off days',
                default => $deductFrom,
            };

            throw ValidationException::withMessages([
                'deduct_from' => [
                    sprintf(
                        'Insufficient %s balance. Available: %s day(s), requested: %s day(s).',
                        $label,
                        rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.'),
                    ),
                ],
            ]);
        }
    }
}
