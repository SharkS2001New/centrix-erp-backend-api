<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\PayrollDeductionType;
use App\Models\PayrollRun;
use App\Models\PlatformSubscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollDeductionTypeListStatusTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        if ($this->user->organization_id) {
            PlatformSubscription::query()->firstOrCreate(
                ['organization_id' => $this->user->organization_id],
                [
                    'status' => 'active',
                    'current_period_start' => now()->subMonth()->toDateString(),
                    'current_period_end' => now()->addYear()->toDateString(),
                    'renewal_price' => 0,
                    'amount' => 0,
                    'currency' => 'KES',
                ],
            );
        }
        Sanctum::actingAs($this->user);
    }

    public function test_index_defaults_to_pending_and_excludes_applied_one_time(): void
    {
        $orgId = (int) $this->user->organization_id;
        $employee = Employee::query()->where('organization_id', $orgId)->firstOrFail();

        $pendingRes = $this->postJson('/api/v1/payroll-deduction-types', [
            'deduction_code' => 'OT-PENDING-'.uniqid(),
            'name' => 'pending shortage',
            'calc_type' => 'fixed',
            'default_amount' => 100,
            'is_active' => true,
            'applies_to_all' => false,
            'frequency' => 'one_time',
            'employee_ids' => [$employee->id],
        ])->assertCreated();

        $pendingId = (int) $pendingRes->json('id');

        $applied = PayrollDeductionType::create([
            'organization_id' => $orgId,
            'deduction_code' => 'OT-APPLIED-'.uniqid(),
            'name' => 'already deducted',
            'calc_type' => 'fixed',
            'default_amount' => 50,
            'is_active' => false,
            'applies_to_all' => false,
            'frequency' => PayrollDeductionType::FREQUENCY_ONE_TIME,
        ]);

        $runId = PayrollRun::query()->value('id');
        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'deduction_type_id' => $applied->id,
            'name' => $applied->name,
            'calc_type' => 'fixed',
            'amount' => 50,
            'is_active' => false,
            'frequency' => EmployeeDeduction::FREQUENCY_ONE_TIME,
            'payroll_run_id' => $runId,
        ]);

        $pendingList = $this->getJson('/api/v1/payroll-deduction-types')
            ->assertOk()
            ->json('data');

        $pendingIds = collect($pendingList)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($pendingId, $pendingIds);
        $this->assertNotContains((int) $applied->id, $pendingIds);

        $pendingRow = collect($pendingList)->firstWhere('id', $pendingId);
        $this->assertSame('pending', $pendingRow['payroll_status'] ?? null);
        $this->assertNull($pendingRow['applied_on'] ?? null);

        $appliedList = $this->getJson('/api/v1/payroll-deduction-types?payroll_status=applied')
            ->assertOk()
            ->json('data');

        $appliedIds = collect($appliedList)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $applied->id, $appliedIds);
        $this->assertNotContains($pendingId, $appliedIds);

        $appliedRow = collect($appliedList)->firstWhere('id', $applied->id);
        $this->assertSame('applied', $appliedRow['payroll_status'] ?? null);
    }
}
