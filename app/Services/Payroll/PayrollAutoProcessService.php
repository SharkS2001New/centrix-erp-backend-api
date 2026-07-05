<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Attendance\AttendanceDayPolicy;
use Illuminate\Support\Collection;

class PayrollAutoProcessService
{
    public function __construct(
        protected PayrollEarningsService $earnings,
        protected AttendanceDayPolicy $dayPolicy,
    ) {}

    /**
     * @param  array{
     *   department_id?: int|null,
     *   include_allowances?: bool,
     *   include_other_deductions?: bool,
     *   include_deductions?: bool,
     *   include_overtime?: bool,
     *   use_attendance_proration?: bool
     * }  $options
     * @return array{lines: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function buildLines(PayrollRun $run, ?int $orgId, array $options = []): array
    {
        $period = $run->payPeriod;
        if (! $period) {
            return ['lines' => [], 'skipped' => []];
        }

        $earningsOptions = [
            'include_allowances' => (bool) ($options['include_allowances'] ?? true),
            'include_other_deductions' => (bool) (
                $options['include_other_deductions']
                ?? $options['include_deductions']
                ?? true
            ),
            'include_overtime' => (bool) ($options['include_overtime'] ?? true),
            'use_attendance_proration' => (bool) ($options['use_attendance_proration'] ?? true),
        ];

        $departmentId = $options['department_id'] ?? null;

        $employees = Employee::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where('employment_status', 'active')
            ->where('is_active', true)
            ->where('base_salary', '>', 0)
            ->orderBy('full_name')
            ->get();

        $this->dayPolicy->primeScheduleContext(
            $employees,
            $period->period_start->format('Y-m-d'),
            $period->period_end->format('Y-m-d'),
        );

        $lines = [];
        $skipped = [];

        foreach ($employees as $employee) {
            if (! $employee->shift_id) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'name' => $employee->full_name,
                    'reason' => 'No work shift assigned',
                ];

                continue;
            }

            $built = $this->earnings->buildLineInput($employee, $period, $earningsOptions);
            if ($built === null) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'name' => $employee->full_name,
                    'reason' => 'No scheduled work days in pay period',
                ];

                continue;
            }

            $lines[] = $built;
        }

        $this->dayPolicy->clearScheduleContext();

        return compact('lines', 'skipped');
    }
}
