<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeOvertime;
use App\Models\Organization;
use App\Models\User;
use App\Services\Payroll\PayrollEarningsService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollApprovedOvertimeOnlyTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_approved_overtime_in_period_excludes_pending_and_rejected(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'employee_code' => 'EMP#OT'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#OT'.strtoupper(uniqid()),
            'first_name' => 'Ot',
            'last_name' => 'Only',
            'full_name' => 'Ot Only',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 50000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $base = [
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'hours' => 2,
            'rate_mode' => 'manual',
            'hourly_rate' => 500,
            'rate_multiplier' => 1.5,
        ];

        EmployeeOvertime::query()->create(array_merge($base, [
            'work_date' => '2026-08-10',
            'amount' => 1500,
            'status' => 'approved',
            'notes' => 'approved',
        ]));
        EmployeeOvertime::query()->create(array_merge($base, [
            'work_date' => '2026-08-11',
            'amount' => 2000,
            'status' => 'pending',
            'notes' => 'pending',
        ]));
        EmployeeOvertime::query()->create(array_merge($base, [
            'work_date' => '2026-08-12',
            'amount' => 900,
            'status' => 'rejected',
            'notes' => 'rejected',
        ]));

        $total = app(PayrollEarningsService::class)->approvedOvertimeInPeriod(
            $employee->id,
            '2026-08-01',
            '2026-08-31',
        );

        $this->assertEqualsWithDelta(1500.0, $total, 0.01);
    }
}
