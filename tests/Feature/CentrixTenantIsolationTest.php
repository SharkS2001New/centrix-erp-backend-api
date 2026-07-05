<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CurrentStock;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Support\SqlLikeSearch;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CentrixTenantIsolationTest extends TestCase
{
    use RefreshesErpDatabase;

    /** @return array{org: Organization, branch: Branch, user: User} */
    protected function createOtherTenant(): array
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = Organization::findOrFail($admin->organization_id);

        $orgB = Organization::create([
            'company_code' => 'ISOL'.substr(uniqid(), -4),
            'org_name' => 'Isolation Tenant Ltd',
            'org_email' => 'isolate@example.com',
            'primary_tel' => '0700000099',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $orgA->enabled_modules,
            'module_settings' => $orgA->module_settings,
        ]);

        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'ISO-HQ',
            'branch_name' => 'Isolation HQ',
            'branch_type' => 'retail',
            'branch_phone' => '0700000099',
            'branch_address' => 'Nairobi',
            'is_active' => true,
        ]);

        $userB = User::create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'role_id' => Role::query()->firstOrFail()->id,
            'username' => 'isolate_user_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Isolation Tenant User',
            'is_active' => true,
            'access_scope' => 'org',
        ]);

        return ['org' => $orgB, 'branch' => $branchB, 'user' => $userB];
    }

    public function test_product_search_is_scoped_to_authenticated_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        ['org' => $orgB, 'branch' => $branchB] = $this->createOtherTenant();

        Product::query()->create([
            'product_code' => 'ISO-SECRET-CODE',
            'product_name' => 'Isolation Secret Widget',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'vat_id' => 1,
            'organization_id' => $orgB->id,
            'branch_id' => null,
            'stock_in_shop' => 5,
            'stock_in_store' => 0,
        ]);

        Sanctum::actingAs($admin);

        $codes = collect($this->getJson('/api/v1/products?q=Isolation+Secret&per_page=50')
            ->assertOk()
            ->json('data'))
            ->pluck('product_code')
            ->all();

        $this->assertNotContains('ISO-SECRET-CODE', $codes);

        $query = Product::query()->whereNull('deleted_at');
        app(ProductCatalogScopeService::class)->scopeForUser($query, $admin, request());
        SqlLikeSearch::applyProductSearch($query, 'Isolation Secret');

        $this->assertFalse($query->where('product_code', 'ISO-SECRET-CODE')->exists());
    }

    public function test_customer_search_is_scoped_to_authenticated_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        ['org' => $orgB, 'branch' => $branchB, 'user' => $userB] = $this->createOtherTenant();

        Customer::query()->create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'customer_num' => 88001,
            'customer_name' => 'Isolation Secret Customer',
            'customer_type' => 'regular',
            'phone_number' => '0700888001',
            'created_by' => $userB->id,
        ]);

        Sanctum::actingAs($admin);

        $names = collect($this->getJson('/api/v1/customers?q=Isolation+Secret&per_page=50')
            ->assertOk()
            ->json('data'))
            ->pluck('customer_name')
            ->all();

        $this->assertNotContains('Isolation Secret Customer', $names);
    }

    public function test_sales_search_is_scoped_to_authenticated_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        ['org' => $orgB, 'branch' => $branchB, 'user' => $userB] = $this->createOtherTenant();

        Sale::query()->create([
            'order_num' => 88776655,
            'branch_id' => $branchB->id,
            'organization_id' => $orgB->id,
            'channel' => 'backend',
            'cashier_id' => $userB->id,
            'customer_name_override' => 'Isolation Secret Order Customer',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 100,
            'amount_paid' => 100,
        ]);

        Sanctum::actingAs($admin);

        $orderNums = collect($this->getJson('/api/v1/sales?q=88776655&per_page=50')
            ->assertOk()
            ->json('data'))
            ->pluck('order_num')
            ->map(fn ($value) => (int) $value)
            ->all();

        $this->assertNotContains(88776655, $orderNums);

        $nameMatches = collect($this->getJson('/api/v1/sales?q=Isolation+Secret+Order&per_page=50')
            ->assertOk()
            ->json('data'))
            ->pluck('customer_name_override')
            ->all();

        $this->assertNotContains('Isolation Secret Order Customer', $nameMatches);
    }

    public function test_sales_search_excludes_materialized_legacy_import_rows(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $centrix = Sale::query()->create([
            'order_num' => 77665544,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_name_override' => 'Centrix Searchable Customer',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 150,
            'amount_paid' => 150,
            'fulfillment_meta' => null,
        ]);

        $legacy = Sale::query()->create([
            'order_num' => 77665545,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'customer_name_override' => 'Centrix Searchable Customer Legacy',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 999,
            'amount_paid' => 999,
            'fulfillment_meta' => [
                'legacy_import' => true,
                'legacy_order_num' => 77665544,
                'legacy_order_label' => 'POS-77665544',
                'legacy_sale_date' => '2026-06-01',
                'legacy_source' => 'pos_masters',
            ],
        ]);

        $ids = collect($this->getJson('/api/v1/sales?q=Centrix+Searchable&per_page=50')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $centrix->id, $ids);
        $this->assertNotContains((int) $legacy->id, $ids);
    }

    public function test_catalog_summary_rejects_branch_id_from_another_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        ['branch' => $branchB] = $this->createOtherTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/products/catalog-summary?branch_id='.$branchB->id)
            ->assertForbidden();
    }

    public function test_product_list_rejects_foreign_branch_id_for_stock_overlay(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        ['branch' => $branchB] = $this->createOtherTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/products?branch_id='.$branchB->id.'&per_page=5')
            ->assertForbidden();
    }

    public function test_branch_stock_overlay_uses_only_requested_branch_quantities(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;
        $hqId = (int) $admin->branch_id;

        $branchTwo = Branch::query()->create([
            'organization_id' => $orgId,
            'branch_code' => 'ISO-BR2',
            'branch_name' => 'Isolation Branch Two',
            'branch_type' => 'retail',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'product_code' => 'ISO-BRANCH-STOCK',
            'product_name' => 'Branch Stock Probe',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'vat_id' => 1,
            'organization_id' => $orgId,
            'branch_id' => null,
            'stock_in_shop' => 1,
            'stock_in_store' => 1,
        ]);

        CurrentStock::query()->updateOrCreate(
            ['branch_id' => $hqId, 'product_code' => $product->product_code],
            ['shop_quantity' => 11, 'store_quantity' => 0],
        );

        CurrentStock::query()->updateOrCreate(
            ['branch_id' => $branchTwo->id, 'product_code' => $product->product_code],
            ['shop_quantity' => 99, 'store_quantity' => 0],
        );

        Sanctum::actingAs($admin);

        $hqRow = collect($this->getJson('/api/v1/products?branch_id='.$hqId.'&q=ISO-BRANCH-STOCK&per_page=5')
            ->assertOk()
            ->json('data'))
            ->firstWhere('product_code', 'ISO-BRANCH-STOCK');

        $branchTwoRow = collect($this->getJson('/api/v1/products?branch_id='.$branchTwo->id.'&q=ISO-BRANCH-STOCK&per_page=5')
            ->assertOk()
            ->json('data'))
            ->firstWhere('product_code', 'ISO-BRANCH-STOCK');

        $this->assertNotNull($hqRow);
        $this->assertNotNull($branchTwoRow);
        $this->assertSame(11.0, (float) $hqRow['stock_in_shop']);
        $this->assertSame(99.0, (float) $branchTwoRow['stock_in_shop']);
    }

    public function test_report_filters_reject_branch_id_from_another_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        ['branch' => $branchB] = $this->createOtherTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/daily-sales?branch_id='.$branchB->id.'&per_page=5')
            ->assertForbidden();
    }

    public function test_sales_by_customer_report_excludes_other_organization_customers(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        ['org' => $orgB, 'branch' => $branchB, 'user' => $userB] = $this->createOtherTenant();

        Customer::query()->create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'customer_num' => 88002,
            'customer_name' => 'Isolation Report Customer',
            'customer_type' => 'regular',
            'phone_number' => '0700888002',
            'created_by' => $userB->id,
        ]);

        Sanctum::actingAs($admin);

        $names = collect($this->getJson('/api/v1/reports/sales-by-customer?q=Isolation+Report+Customer&per_page=50')
            ->assertOk()
            ->json('data'))
            ->pluck('customer_name')
            ->all();

        $this->assertNotContains('Isolation Report Customer', $names);
    }
}
