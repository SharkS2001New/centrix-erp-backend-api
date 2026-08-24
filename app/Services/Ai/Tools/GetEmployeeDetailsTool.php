<?php

namespace App\Services\Ai\Tools;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiEmployees;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

/**
 * Full HR employee profile lookup for AI chat (salary, contacts, employment, statutory).
 */
class GetEmployeeDetailsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;
    use ResolvesAiEmployees;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_employee_details';
    }

    public function description(): string
    {
        return 'Look up a Centrix HR employee profile: basic/base salary, allowance, job title, department, '
            .'hire dates, contacts, KRA/NSSF/SHA, bank accounts, and deductions. '
            .'Use for questions about an employee\'s salary, pay, role, or any employee master-data field. '
            .'Pass employee_name (full name, employee code, payroll number, or login username) and/or employee_id. '
            .'Never invent salary — always call this tool. Identify people by name and username in the reply.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'employee_name' => [
                    'type' => 'string',
                    'description' => 'Employee full name, employee code, payroll number, or linked login username.',
                ],
                'employee_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric employee id when known from an @Employee mention.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional search when employee_name is unknown (partial name/code).',
                ],
            ],
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
            || $this->permissions->hasPermission($user, 'hr.employees.view', $gate)
            || $this->permissions->hasPermission($user, 'hr.view', $gate)
            || $this->permissions->hasPermission($user, 'hr.payroll.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view employee records.',
                'screens' => $this->screens(),
            ];
        }

        $orgId = (int) $organization->id;
        $employeeId = (int) ($arguments['employee_id'] ?? 0);
        $needle = trim((string) ($arguments['employee_name'] ?? $arguments['query'] ?? ''));

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
                'message' => 'Provide employee_name, query, or employee_id.',
                'screens' => $this->screens(),
            ];
        }

        return [
            'employee' => $this->presentFullEmployee($employee),
            'field_notes' => [
                'basic_salary' => 'Same as Centrix field base_salary (KES). Quote this when users ask for basic salary.',
                'base_salary' => 'Stored basic / base salary on the employee record.',
                'monthly_allowance' => 'Additional monthly allowance if configured.',
            ],
            'screens' => [
                [
                    'label' => 'Employee profile',
                    'path' => '/hr/employees/'.$employee->id,
                ],
                ...$this->screens(),
            ],
            'tip' => 'Answer with the employee\'s name and username. Use basic_salary / base_salary for pay questions. '
                .'Include assigned shift times and pays_sha when relevant. Do not say you lack access when this tool returned data. '
                .'For month salary / net pay with attendance, call get_employee_payroll_preview — do not invent 22-day formulas.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentFullEmployee(Employee $employee): array
    {
        $name = (string) ($employee->full_name ?: trim($employee->first_name.' '.$employee->last_name));
        $baseSalary = $employee->base_salary !== null ? (float) $employee->base_salary : null;
        $allowance = $employee->monthly_allowance !== null ? (float) $employee->monthly_allowance : null;

        return [
            'name' => $name,
            'username' => $employee->user?->username,
            'employee_code' => $employee->employee_code ? (string) $employee->employee_code : null,
            'payroll_number' => $employee->payroll_number ? (string) $employee->payroll_number : null,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'job_title' => $employee->job_title,
            'employment_status' => $employee->employment_status,
            'employment_type' => $employee->employment_type,
            'is_active' => (bool) $employee->is_active,
            'department' => $employee->department?->department_name,
            'position' => $employee->position?->position_title,
            'shift' => $employee->shift
                ? [
                    'name' => (string) ($employee->shift->shift_name ?? ''),
                    'code' => $employee->shift->shift_code ? (string) $employee->shift->shift_code : null,
                    'start_time' => $this->formatClock($employee->shift->start_time),
                    'end_time' => $this->formatClock($employee->shift->end_time),
                    'lunch_minutes' => (int) ($employee->shift->lunch_minutes ?? 0),
                    'work_weekdays' => $employee->shift->scheduledWeekdays(),
                    'works_saturday' => (bool) ($employee->shift->works_saturday ?? false),
                    'works_sunday' => (bool) ($employee->shift->works_sunday ?? false),
                ]
                : null,
            'branch' => $employee->branch?->branch_name,
            'reports_to' => $employee->reportsTo
                ? [
                    'name' => $employee->reportsTo->full_name,
                    'employee_code' => $employee->reportsTo->employee_code,
                ]
                : null,
            'pay' => [
                'basic_salary' => $baseSalary,
                'base_salary' => $baseSalary,
                'monthly_allowance' => $allowance,
                'pay_frequency' => $employee->pay_frequency,
                'currency' => 'KES',
            ],
            'dates' => [
                'hire_date' => optional($employee->hire_date)?->toDateString(),
                'confirmation_date' => optional($employee->confirmation_date)?->toDateString(),
                'probation_end_date' => optional($employee->probation_end_date)?->toDateString(),
                'contract_start_date' => optional($employee->contract_start_date)?->toDateString(),
                'contract_end_date' => optional($employee->contract_end_date)?->toDateString(),
                'date_of_birth' => optional($employee->date_of_birth)?->toDateString(),
            ],
            'contacts' => [
                'email' => $employee->email,
                'personal_email' => $employee->personal_email,
                'phone' => $employee->phone,
                'alt_phone' => $employee->alt_phone,
                'physical_address' => $employee->physical_address,
                'postal_address' => $employee->postal_address,
                'city' => $employee->city,
                'county' => $employee->county,
                'country' => $employee->country,
            ],
            'identity' => [
                'gender' => $employee->gender,
                'nationality' => $employee->nationality,
                'national_id' => $employee->national_id,
                'id_document_type' => $employee->id_document_type,
                'marital_status' => $employee->marital_status,
            ],
            'statutory' => [
                'kra_pin' => $employee->kra_pin,
                'nssf_number' => $employee->nssf_number,
                'sha_number' => $employee->sha_number,
                'pays_sha' => (bool) ($employee->pays_sha ?? false),
                'housing_levy_number' => $employee->housing_levy_number,
            ],
            'bank_accounts' => $employee->relationLoaded('bankAccounts')
                ? $employee->bankAccounts->map(fn ($row) => [
                    'bank_name' => $row->bank_name,
                    'bank_branch' => $row->bank_branch,
                    'account_name' => $row->account_name,
                    'account_number' => $row->account_number,
                    'payment_method' => $row->payment_method,
                    'is_primary' => (bool) ($row->is_primary ?? false),
                ])->values()->all()
                : [],
            'deductions' => $employee->relationLoaded('deductions')
                ? $employee->deductions->map(fn ($row) => [
                    'name' => $row->name,
                    'amount' => $row->amount !== null ? (float) $row->amount : null,
                    'percentage' => $row->percentage !== null ? (float) $row->percentage : null,
                    'calc_type' => $row->calc_type,
                    'frequency' => $row->frequency,
                    'is_active' => (bool) ($row->is_active ?? true),
                ])->values()->all()
                : [],
            'profile_path' => '/hr/employees/'.$employee->id,
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
            ['label' => 'Employees', 'path' => '/hr/employees'],
            ['label' => 'Payroll', 'path' => '/hr/payroll'],
            ['label' => "Today's attendance", 'path' => '/hr/attendance'],
        ];
    }
}
