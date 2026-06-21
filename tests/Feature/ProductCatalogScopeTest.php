<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ProductCatalogScopeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_single_branch_org_creates_organization_wide_products(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->postJson('/api/v1/products', [
            'product_name' => 'Scoped Sugar',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 120,
            'vat_id' => 1,
            'catalog_scope' => 'branch',
            'branch_id' => 999,
        ])->assertCreated()
            ->assertJsonPath('catalog_scope', 'organization')
            ->assertJsonPath('branch_id', null);
    }

    public function test_multi_branch_org_can_create_branch_and_organization_products(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        Branch::query()->create([
            'organization_id' => $orgId,
            'branch_code' => 'BR2',
            'branch_name' => 'Branch Two',
            'branch_type' => 'retail',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $branchTwoId = (int) Branch::query()
            ->where('organization_id', $orgId)
            ->where('branch_code', 'BR2')
            ->value('id');

        $this->postJson('/api/v1/products', [
            'product_name' => 'Org Wide Rice',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 90,
            'vat_id' => 1,
            'catalog_scope' => 'organization',
        ])->assertCreated()
            ->assertJsonPath('catalog_scope', 'organization');

        $this->postJson('/api/v1/products', [
            'product_name' => 'Branch Only Flour',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 75,
            'vat_id' => 1,
            'catalog_scope' => 'branch',
            'branch_id' => $branchTwoId,
        ])->assertCreated()
            ->assertJsonPath('catalog_scope', 'branch')
            ->assertJsonPath('branch_id', $branchTwoId);
    }

    public function test_branch_limited_user_only_lists_visible_products(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;
        $hqId = (int) $admin->branch_id;

        $branchTwo = Branch::query()->create([
            'organization_id' => $orgId,
            'branch_code' => 'BR3',
            'branch_name' => 'Branch Three',
            'branch_type' => 'retail',
            'is_active' => true,
        ]);

        Product::query()->create([
            'product_code' => 'ORG-WIDE-1',
            'product_name' => 'Org Wide Item',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'vat_id' => 1,
            'organization_id' => $orgId,
            'branch_id' => null,
        ]);

        Product::query()->create([
            'product_code' => 'BR3-ONLY-1',
            'product_name' => 'Branch Three Item',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'vat_id' => 1,
            'organization_id' => $orgId,
            'branch_id' => $branchTwo->id,
        ]);

        $branchUser = User::create([
            'organization_id' => $orgId,
            'branch_id' => $hqId,
            'role_id' => $admin->role_id,
            'username' => 'branchcatalog',
            'email' => 'branchcatalog@example.com',
            'password' => bcrypt('secret'),
            'full_name' => 'Branch Catalog User',
            'is_admin' => 0,
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        Sanctum::actingAs($branchUser);

        $codes = collect($this->getJson('/api/v1/products?per_page=200')->json('data'))
            ->pluck('product_code')
            ->all();

        $this->assertContains('ORG-WIDE-1', $codes);
        $this->assertNotContains('BR3-ONLY-1', $codes);
    }

    public function test_capabilities_include_catalog_metadata(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('catalog.branch_count', 1)
            ->assertJsonPath('catalog.multi_branch', false)
            ->assertJsonPath('catalog.head_office_branch_code', 'HQ');
    }
}
