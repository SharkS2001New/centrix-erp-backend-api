<?php

namespace Tests\Feature;

use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BranchListFilterTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_apply_branch_list_filter_respects_optional_branch_for_org_wide_users(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->assertTrue(app(UserAccessService::class)->isOrgWide($admin));

        $branchId = (int) $admin->branch_id;
        $request = request()->merge(['filter' => ['branch_id' => $branchId]]);

        $query = \App\Models\Expense::query()->where('organization_id', $admin->organization_id);
        app(UserAccessService::class)->applyBranchListFilter($query, $admin, $request);

        $this->assertStringContainsString('branch_id', strtolower($query->toSql()));
    }

    public function test_supplier_payment_accepts_branch_id(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $supplierId = \App\Models\Supplier::query()
            ->where('organization_id', $admin->organization_id)
            ->value('id');
        $methodId = DB::table('payment_methods')
            ->where('organization_id', $admin->organization_id)
            ->value('id')
            ?? DB::table('payment_methods')->value('id');

        $this->assertNotEmpty($supplierId);
        $this->assertNotEmpty($methodId);

        $payment = SupplierPayment::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'supplier_id' => $supplierId,
            'payment_method_id' => $methodId,
            'amount_paid' => 1,
            'date_paid' => now()->toDateString(),
            'paid_by' => $admin->id,
        ]);

        $this->assertSame((int) $admin->branch_id, (int) $payment->fresh()->branch_id);
        $this->assertDatabaseHas('supplier_payments', [
            'id' => $payment->id,
            'branch_id' => $admin->branch_id,
        ]);
    }
}
