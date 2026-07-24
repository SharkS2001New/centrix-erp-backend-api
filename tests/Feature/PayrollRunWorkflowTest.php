<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollRunWorkflowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_admin_can_approve_pending_payroll_run(): void
    {
        $run = $this->createRun('pending_approval');

        $this->postJson("/api/v1/payroll/runs/{$run->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('approved_by', $this->admin->id);

        $this->assertNotNull($run->fresh()->approved_at);
    }

    public function test_admin_can_mark_processed_payroll_run_as_paid(): void
    {
        $run = $this->createRun('processed');
        $employee = Employee::firstOrFail();

        PayrollLine::create([
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

        $this->postJson("/api/v1/payroll/runs/{$run->id}/mark-paid", [
            'payment_reference' => 'BANK-2026-06',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('paid_by', $this->admin->id)
            ->assertJsonPath('payment_reference', 'BANK-2026-06');

        $this->assertNotNull($run->fresh()->paid_at);
    }

    public function test_mark_paid_rejects_non_processed_runs(): void
    {
        $run = $this->createRun('approved');

        $this->postJson("/api/v1/payroll/runs/{$run->id}/mark-paid")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only processed payroll runs can be marked as paid.');
    }

    public function test_process_run_auto_calculates_non_zero_paye(): void
    {
        $run = $this->createRun('draft');
        $employee = Employee::query()
            ->where('organization_id', $this->admin->organization_id)
            ->where('is_active', true)
            ->firstOrFail();

        $this->postJson("/api/v1/payroll/runs/{$run->id}/process", [
            'auto_calculate' => true,
            'close_cycle' => false,
            'lines' => [
                [
                    'employee_id' => $employee->id,
                    'basic_salary' => 50000,
                    'allowances' => 0,
                    'gross_pay' => 50000,
                    'other_deductions' => 0,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $line = PayrollLine::query()
            ->where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertEquals(50000.0, (float) $line->gross_pay);
        $this->assertGreaterThan(0, (float) $line->paye);
        $this->assertEqualsWithDelta(5845.85, (float) $line->paye, 0.02);
        $this->assertEquals(0.0, (float) data_get($line->statutory_meta, 'insurance_relief', -1));
    }

    public function test_processed_run_can_be_reprocessed_to_refresh_paye(): void
    {
        $run = $this->createRun('processed');
        $employee = Employee::query()
            ->where('organization_id', $this->admin->organization_id)
            ->where('is_active', true)
            ->firstOrFail();

        PayrollLine::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross_pay' => 50000,
            'nssf' => 3000,
            'shif' => 1375,
            'housing_levy' => 750,
            'paye' => 0,
            'other_deductions' => 0,
            'deductions' => 5125,
            'net_pay' => 44875,
            'taxable_income' => 44875,
            'employer_nssf' => 3000,
            'employer_housing' => 750,
        ]);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/process", [
            'auto_calculate' => true,
            'close_cycle' => false,
            'lines' => [
                [
                    'employee_id' => $employee->id,
                    'gross_pay' => 50000,
                    'other_deductions' => 0,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $line = PayrollLine::query()
            ->where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertGreaterThan(0, (float) $line->paye);
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
