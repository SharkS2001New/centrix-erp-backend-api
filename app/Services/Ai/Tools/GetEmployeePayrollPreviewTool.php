<?php

namespace App\Services\Ai\Tools;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiEmployees;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Attendance\AttendanceDayPolicy;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\Payroll\KenyaStatutoryCalculator;
use App\Services\Payroll\PayrollEarningsService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Preview one employee's payroll for a period using the same Centrix earnings + Kenya statutory engine as HR → Payroll.
 */
class GetEmployeePayrollPreviewTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;
    use ResolvesAiEmployees;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected PayrollEarningsService $earnings,
        protected KenyaStatutoryCalculator $statutory,
        protected AttendanceDayPolicy $dayPolicy,
    ) {}

    public function name(): string
    {
        return 'get_employee_payroll_preview';
    }

    public function description(): string
    {
        return 'Preview Centrix payroll for one employee for a month/period using their assigned shift, '
            .'base salary, attendance proration, lateness, allowances, approved overtime only, and Kenya statutory '
            .'(NSSF, SHIF/SHA when pays_sha is on, housing levy, PAYE). '
            .'Overtime with status pending is excluded until approved — do not tell users pending OT will appear in pay. '
            .'Use when the user asks how much someone would earn, salary for a month, payslip preview, '
            .'or whether SHA/PAYE apply. Never invent formulas or assume 22 days / 8 hours — always call this tool. '
            .'Pass employee_name or employee_id, plus relative_date=this_month/last_month or year_month=YYYY-MM '
            .'or from_date/to_date. Identify people by name and username, never numeric id.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'employee_name' => [
                    'type' => 'string',
                    'description' => 'Employee full name, employee code, payroll number, or login username.',
                ],
                'employee_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric employee id when known from an @Employee mention.',
                ],
                'relative_date' => [
                    'type' => 'string',
                    'enum' => ['this_month', 'last_month', 'this_month_to_date'],
                    'description' => 'Prefer for "this month" / "last month" / month so far.',
                ],
                'year_month' => [
                    'type' => 'string',
                    'description' => 'Calendar month YYYY-MM (e.g. 2026-08 for August 2026).',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Period start YYYY-MM-DD when not using relative_date/year_month.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Period end YYYY-MM-DD when not using relative_date/year_month.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }

        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'hr.payroll.view', $gate)
            || $this->permissions->hasPermission($user, 'hr.employees.view', $gate)
            || $this->permissions->hasPermission($user, 'hr.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to preview payroll.',
                'screens' => $this->screens(),
            ];
        }

        $orgId = (int) $organization->id;
        $employeeId = (int) ($arguments['employee_id'] ?? 0);
        $needle = trim((string) ($arguments['employee_name'] ?? ''));

        $employee = null;
        if ($employeeId > 0) {
            $employee = $this->resolveEmployeeById($orgId, $employeeId, true);
            if (! $employee) {
                return [
                    'error' => true,
                    'message' => 'No employee found for that id in this organization.',
                    'screens' => $this->screens(),
                ];
            }
        } elseif ($needle !== '') {
            $resolved = $this->resolveEmployeeByName($orgId, $needle, true);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, ['screens' => $this->screens()]);
            }
            $employee = $resolved['employee'];
        } else {
            return [
                'error' => true,
                'message' => 'Provide employee_name or employee_id.',
                'screens' => $this->screens(),
            ];
        }

        /** @var Employee $employee */
        $employee->loadMissing(['shift', 'user:id,username,full_name']);

        [$from, $to] = $this->resolvePeriod($arguments, $organization);
        $hr = HrPayrollSettingsResolver::forOrganizationId($orgId);

        if (! $employee->shift_id || ! $employee->shift) {
            return [
                'error' => true,
                'message' => 'This employee has no work shift assigned. Centrix payroll requires a shift to calculate expected days/hours.',
                'employee' => $this->presentPerson($employee),
                'period' => ['from_date' => $from, 'to_date' => $to],
                'screens' => $this->screens(),
                'tip' => 'Assign a shift under /hr/employees then retry, or open /hr/payroll.',
            ];
        }

        $baseSalary = (float) ($employee->base_salary ?? 0);
        if ($baseSalary <= 0) {
            return [
                'error' => true,
                'message' => 'This employee has no base salary set on their HR profile. Centrix cannot calculate payroll without it.',
                'employee' => $this->presentPerson($employee),
                'shift' => $this->presentShift($employee->shift),
                'period' => ['from_date' => $from, 'to_date' => $to],
                'screens' => $this->screens(),
                'tip' => 'Set basic salary on the employee profile at /hr/employees, then retry.',
            ];
        }

        $period = new PayPeriod([
            'organization_id' => $orgId,
            'period_code' => 'AI-PREVIEW-'.$from.'_'.$to,
            'period_start' => $from,
            'period_end' => $to,
            'status' => 'open',
        ]);

        $earningsOptions = [
            'include_allowances' => true,
            'include_other_deductions' => (bool) ($hr['include_other_deductions_in_payroll'] ?? true),
            'include_overtime' => (bool) ($hr['include_overtime_in_payroll'] ?? true),
            'use_attendance_proration' => true,
        ];

        $this->dayPolicy->primeScheduleContext(collect([$employee]), $from, $to);
        try {
            $built = $this->earnings->buildLineInput($employee, $period, $earningsOptions);
        } finally {
            $this->dayPolicy->clearScheduleContext();
        }

        if ($built === null) {
            return [
                'error' => true,
                'message' => 'Centrix payroll engine skipped this employee for the period (no scheduled work days, or attendance required with no payable days yet).',
                'employee' => $this->presentPerson($employee),
                'shift' => $this->presentShift($employee->shift),
                'contract' => [
                    'basic_salary' => $baseSalary,
                    'monthly_allowance' => $employee->monthly_allowance !== null ? (float) $employee->monthly_allowance : null,
                    'pays_sha' => (bool) ($employee->pays_sha ?? false),
                    'currency' => 'KES',
                ],
                'period' => ['from_date' => $from, 'to_date' => $to],
                'org_payroll_settings' => [
                    'require_attendance_for_payroll' => (bool) ($hr['require_attendance_for_payroll'] ?? false),
                    'payroll_month_days_basis' => $hr['payroll_month_days_basis'] ?? null,
                    'standard_work_hours_per_day' => $hr['standard_work_hours_per_day'] ?? null,
                ],
                'screens' => $this->screens(),
            ];
        }

        $meta = is_array($built['payroll_meta'] ?? null) ? $built['payroll_meta'] : [];
        $periodGross = (float) ($built['gross_pay'] ?? 0);
        $other = (float) ($built['other_deductions'] ?? 0);
        $contractBasic = (float) ($meta['contract_monthly_salary'] ?? $baseSalary);
        $monthlyAllowance = (float) ($meta['monthly_allowance'] ?? 0);
        $statutoryGross = (float) ($meta['contract_gross_for_statutory'] ?? round($contractBasic + $monthlyAllowance, 2));
        if ($statutoryGross <= 0) {
            $statutoryGross = $periodGross;
        }

        $paysSha = (bool) ($employee->pays_sha ?? true);
        $calc = $this->statutory->calculateMonthly($statutoryGross, $other, 0, $orgId, $paysSha);
        $calc = $this->applyStatutoryToPeriodGross($calc, $periodGross, $other);
        $calc['statutory_gross'] = $statutoryGross;
        $calc['period_gross'] = $periodGross;
        $calc['statutory_based_on_contract_gross'] = true;
        $calc['pays_sha'] = $paysSha;
        $calc['shif_skipped_because_pays_sha_off'] = ! $paysSha;

        return [
            'preview' => true,
            'engine' => 'Centrix PayrollEarningsService + KenyaStatutoryCalculator (same as HR → Payroll)',
            'employee' => $this->presentPerson($employee),
            'shift' => $this->presentShift($employee->shift),
            'contract' => [
                'basic_salary' => $contractBasic,
                'monthly_allowance' => $monthlyAllowance,
                'pays_sha' => $paysSha,
                'currency' => 'KES',
            ],
            'period' => [
                'from_date' => $from,
                'to_date' => $to,
            ],
            'org_payroll_settings' => [
                'require_attendance_for_payroll' => (bool) ($hr['require_attendance_for_payroll'] ?? false),
                'payroll_month_days_basis' => $hr['payroll_month_days_basis'] ?? null,
                'standard_work_hours_per_day' => $hr['standard_work_hours_per_day'] ?? null,
                'include_overtime_in_payroll' => (bool) ($hr['include_overtime_in_payroll'] ?? true),
                'include_other_deductions_in_payroll' => (bool) ($hr['include_other_deductions_in_payroll'] ?? true),
            ],
            'earnings' => [
                'contract_basic' => $contractBasic,
                'period_basic' => (float) ($meta['period_basic'] ?? $built['basic_salary'] ?? 0),
                'allowances_period' => (float) ($built['allowances'] ?? 0),
                'overtime' => (float) ($meta['overtime'] ?? 0),
                'overtime_note' => 'Only approved overtime is included; pending OT is excluded until approved.',
                'period_gross' => $periodGross,
                'other_deductions' => $other,
                'daily_rate' => (float) ($meta['daily_rate'] ?? 0),
                'hour_ratio' => (float) ($meta['hour_ratio'] ?? 1),
                'expected_work_days' => (float) ($meta['expected_work_days'] ?? 0),
                'paid_work_days' => (float) ($meta['paid_work_days'] ?? 0),
                'remaining_days' => (float) ($meta['remaining_days'] ?? 0),
                'expected_hours' => (float) ($meta['expected_hours'] ?? 0),
                'paid_hours' => (float) ($meta['paid_hours'] ?? 0),
                'use_attendance_proration' => (bool) ($meta['use_attendance_proration'] ?? true),
                'attendance' => $meta['attendance'] ?? null,
                'allowance_lines' => $meta['allowance_lines'] ?? [],
                'deductions_detail' => $meta['deductions_detail'] ?? [],
            ],
            'statutory' => [
                'nssf' => (float) $calc['nssf'],
                'shif' => (float) $calc['shif'],
                'housing_levy' => (float) $calc['housing_levy'],
                'paye' => (float) $calc['paye'],
                'personal_relief' => (float) ($calc['personal_relief'] ?? 0),
                'taxable_income' => (float) ($calc['taxable_income'] ?? 0),
                'statutory_gross_used' => $statutoryGross,
                'pays_sha' => $paysSha,
                'note' => $paysSha
                    ? 'SHIF/SHA deducted because pays_sha is enabled on the employee.'
                    : 'SHIF/SHA not deducted because pays_sha is off on the employee.',
            ],
            'totals' => [
                'period_gross' => $periodGross,
                'total_deductions' => (float) $calc['deductions'],
                'net_pay' => (float) $calc['net_pay'],
                'currency' => 'KES',
            ],
            'screens' => $this->screens(),
            'tip' => 'Report these Centrix engine figures — do not invent 22-day/8-hour formulas. '
                .'Explain shift hours, attendance ratio, pays_sha, and that this is a preview (finalize at /hr/payroll). '
                .'Name the person; never use numeric employee id.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: string}
     */
    protected function resolvePeriod(array $arguments, Organization $organization): array
    {
        $yearMonth = trim((string) ($arguments['year_month'] ?? ''));
        if ($yearMonth !== '' && preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m)) {
            $tz = AiSalesDateResolver::calendarAnchor($organization)['timezone'];
            $start = Carbon::createFromDate((int) $m[1], (int) $m[2], 1, $tz)->startOfMonth();

            return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
        }

        return AiSalesDateResolver::resolve($arguments, $organization);
    }

    /**
     * @param  array<string, mixed>  $calc
     * @return array<string, mixed>
     */
    protected function applyStatutoryToPeriodGross(array $calc, float $periodGross, float $other): array
    {
        $periodGross = round(max(0, $periodGross), 2);
        $other = round(max(0, $other), 2);
        $statutory = round(
            (float) $calc['nssf']
            + (float) $calc['shif']
            + (float) $calc['housing_levy']
            + (float) $calc['paye'],
            2,
        );
        $deductions = round($statutory + $other, 2);

        $calc['gross_pay'] = $periodGross;
        $calc['other_deductions'] = $other;
        $calc['deductions'] = $deductions;
        $calc['net_pay'] = round(max(0, $periodGross - $deductions), 2);

        return $calc;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentPerson(Employee $employee): array
    {
        return array_filter([
            'name' => (string) ($employee->full_name ?: trim($employee->first_name.' '.$employee->last_name)),
            'username' => $employee->user?->username,
            'employee_code' => $employee->employee_code ? (string) $employee->employee_code : null,
            'payroll_number' => $employee->payroll_number ? (string) $employee->payroll_number : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentShift(WorkShift $shift): array
    {
        return [
            'name' => (string) ($shift->shift_name ?? ''),
            'code' => $shift->shift_code ? (string) $shift->shift_code : null,
            'start_time' => $this->formatClock($shift->start_time),
            'end_time' => $this->formatClock($shift->end_time),
            'lunch_minutes' => (int) ($shift->lunch_minutes ?? 0),
            'lunch_required' => (bool) ($shift->lunch_required ?? false),
            'crosses_midnight' => (bool) ($shift->crosses_midnight ?? false),
            'work_weekdays' => $shift->scheduledWeekdays(),
            'works_saturday' => (bool) ($shift->works_saturday ?? false),
            'works_sunday' => (bool) ($shift->works_sunday ?? false),
            'works_public_holidays' => (bool) ($shift->works_public_holidays ?? false),
            'use_alternate_hours' => (bool) ($shift->use_alternate_hours ?? false),
            'alternate_start_time' => $this->formatClock($shift->alternate_start_time),
            'alternate_end_time' => $this->formatClock($shift->alternate_end_time),
        ];
    }

    protected function formatClock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = (string) $value;
        if (preg_match('/^(\d{1,2}:\d{2})/', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(): array
    {
        return [
            ['label' => 'Payroll', 'path' => '/hr/payroll'],
            ['label' => 'Employees', 'path' => '/hr/employees'],
            ['label' => 'Shifts', 'path' => '/hr/shifts'],
            ['label' => 'Attendance history', 'path' => '/hr/attendance/history'],
        ];
    }
}
