<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\Sanctum;
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

    public function test_lpo_create_stamps_branch_id(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplierId = \App\Models\Supplier::query()
            ->where('organization_id', $admin->organization_id)
            ->value('id');
        $productCode = \App\Models\Product::query()
            ->where('organization_id', $admin->organization_id)
            ->value('product_code');

        if (! $supplierId || ! $productCode) {
            $this->markTestSkipped('Demo supplier/product required.');
        }

        $response = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplierId,
            'branch_id' => $admin->branch_id,
            'lines' => [
                [
                    'product_code' => $productCode,
                    'ordered_qty' => 1,
                    'cost_price' => 10,
                ],
            ],
        ]);

        if ($response->status() === 403) {
            $this->markTestSkipped('LPO module gated in this profile.');
        }

        $response->assertSuccessful();
        $lpoNo = $response->json('lpo.lpo_no') ?? $response->json('lpo_no') ?? $response->json('data.lpo_no');
        $this->assertNotEmpty($lpoNo);
        $this->assertDatabaseHas('lpo_mst', [
            'lpo_no' => $lpoNo,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
        ]);
    }
}
