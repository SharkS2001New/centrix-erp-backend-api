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
            'pay_period_id' => $period->id,
            'run_date' => now()->toDateString(),
            'status' => $status,
            'total_gross' => 50000,
            'total_net' => 42595,
            'processed_by' => $status === 'processed' ? $this->admin->id : null,
        ]);
    }
}
