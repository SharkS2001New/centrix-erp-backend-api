<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\GetEmployeeDetailsTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiEmployeeDetailsToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_returns_basic_salary_and_profile_fields(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $template->shift_id,
            'employee_code' => 'EMP#SAL'.strtoupper(substr(uniqid(), -4)),
            'payroll_number' => 'PAY-SAL-1',
            'first_name' => 'Sam',
            'last_name' => 'Salary',
            'full_name' => 'Sam Salary',
            'job_title' => 'Cashier',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 75000.50,
            'monthly_allowance' => 5000,
            'kra_pin' => 'A123456789B',
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        /** @var GetEmployeeDetailsTool $tool */
        $tool = app(GetEmployeeDetailsTool::class);
        $result = $tool->execute($admin, ['employee_name' => 'Sam Salary']);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('Sam Salary', $result['employee']['name'] ?? null);
        $this->assertSame(75000.50, (float) ($result['employee']['pay']['basic_salary'] ?? 0));
        $this->assertSame(75000.50, (float) ($result['employee']['pay']['base_salary'] ?? 0));
        $this->assertSame(5000.0, (float) ($result['employee']['pay']['monthly_allowance'] ?? 0));
        $this->assertSame('A123456789B', $result['employee']['statutory']['kra_pin'] ?? null);
        $this->assertSame('Cashier', $result['employee']['job_title'] ?? null);
        $this->assertStringContainsString('/hr/employees/', (string) ($result['employee']['profile_path'] ?? ''));
    }

    public function test_registry_includes_employee_details_tool(): void
    {
        $names = array_map(fn ($tool) => $tool->name(), app(AiToolRegistry::class)->all());
        $this->assertContains('get_employee_details', $names);
    }
}
