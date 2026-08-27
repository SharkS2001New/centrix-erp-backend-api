<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollAutoProcessService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollExcludeEmployeesTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_exclude_employee_ids_omits_lines_from_build(): void
    {
        $run = $this->createRun('draft');
        $employees = Employee::query()
            ->where('organization_id', $this->admin->organization_id)
            ->where('employment_status', 'active')
            ->where('is_active', true)
            ->where('base_salary', '>', 0)
            ->whereNotNull('shift_id')
            ->orderBy('id')
            ->take(2)
            ->get();

        if ($employees->count() < 2) {
            $this->markTestSkipped('Need at least two active salaried employees with shifts.');
        }

        $keep = (int) $employees[0]->id;
        $exclude = (int) $employees[1]->id;

        $service = app(PayrollAutoProcessService::class);
        $built = $service->buildLines($run, (int) $this->admin->organization_id, [
            'exclude_employee_ids' => [$exclude],
            'use_attendance_proration' => false,
        ]);

        $employeeIds = collect($built['lines'])->pluck('employee_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($keep, $employeeIds);
        $this->assertNotContains($exclude, $employeeIds);
    }

    public function test_exclude_lines_endpoint_removes_employees_from_run(): void
    {
        $run = $this->createRun('processed');
        $employees = Employee::query()
            ->where('organization_id', $this->admin->organization_id)
            ->orderBy('id')
            ->take(2)
            ->get();

        if ($employees->count() < 2) {
            $this->markTestSkipped('Need at least two employees.');
        }

        $lines = [];
        foreach ($employees as $employee) {
            $lines[] = PayrollLine::create([
                'payroll_run_id' => $run->id,
                'employee_id' => $employee->id,
                'gross_pay' => 50000,
                'nssf' => 1080,
                'shif' => 1375,
                'housing_levy' => 750,
                'paye' => 4200,
                'other_deductions' => 0,
                'deductions' => 7405,
                'net_pay' => 42595,
                'taxable_income' => 46795,
                'employer_nssf' => 1080,
                'employer_housing' => 750,
            ]);
        }

        $excludeLine = $lines[1];

        $this->postJson("/api/v1/payroll/runs/{$run->id}/exclude-lines", [
            'line_ids' => [$excludeLine->id],
        ])
            ->assertOk()
            ->assertJsonPath('excluded_count', 1);

        $this->assertDatabaseMissing('payroll_lines', [
            'payroll_run_id' => $run->id,
            'employee_id' => $employees[1]->id,
        ]);
        $this->assertDatabaseHas('payroll_lines', [
            'payroll_run_id' => $run->id,
            'employee_id' => $employees[0]->id,
        ]);
    }

    public function test_cannot_exclude_from_paid_run(): void
    {
        $run = $this->createRun('paid');
        $employee = Employee::firstOrFail();

        $line = PayrollLine::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross_pay' => 50000,
            'nssf' => 1080,
            'shif' => 1375,
            'housing_levy' => 750,
            'paye' => 4200,
            'other_deductions' => 0,
            'deductions' => 7405,
            'net_pay' => 42595,
            'taxable_income' => 46795,
            'employer_nssf' => 1080,
            'employer_housing' => 750,
        ]);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/exclude-lines", [
            'line_ids' => [$line->id],
        ])->assertStatus(422);
    }

    protected function createRun(string $status): PayrollRun
    {
        $orgId = (int) $this->admin->organization_id;
        $period = PayPeriod::create([
            'organization_id' => $orgId,
            'period_code' => 'TEST-'.uniqid(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'pay_date' => now()->endOfMonth()->toDateString(),
        ]);

        return PayrollRun::create([
            'organization_id' => $orgId,
            'pay_period_id' => $period->id,
            'run_date' => now()->toDateString(),
            'status' => $status,
            'total_gross' => 50000,
            'total_net' => 42595,
            'processed_by' => $status === 'processed' ? $this->admin->id : null,
        ]);
    }
}
