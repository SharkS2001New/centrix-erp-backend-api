<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TenantCatalogReferenceDataTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_vat_list_is_scoped_to_authenticated_users_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        $orgB = $this->createOrganization('CATREF2', 'Catalog Reference Org Two');

        Vat::create([
            'vat_code' => 'ORG-B-VAT',
            'vat_name' => 'Org B VAT',
            'vat_percentage' => 8,
            'organization_id' => $orgB->id,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/vats?per_page=100')->assertOk();
        $codes = collect($response->json('data'))->pluck('vat_code');

        $this->assertTrue($codes->contains('V'));
        $this->assertFalse($codes->contains('ORG-B-VAT'));

        $orgBVatCount = Vat::query()->where('organization_id', $orgB->id)->count();
        $this->assertSame(1, $orgBVatCount);
        $this->assertSame((int) $orgA->id, (int) Vat::where('vat_code', 'V')->value('organization_id'));
    }

    public function test_two_organizations_can_share_vat_code(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgB = $this->createOrganization('CATREF3', 'Catalog Reference Org Three');

        Vat::create([
            'vat_code' => 'ZERO',
            'vat_name' => 'Org B Zero Rated',
            'vat_percentage' => 0,
            'organization_id' => $orgB->id,
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('vats', [
            'organization_id' => $orgB->id,
            'vat_code' => 'ZERO',
        ]);
    }

    public function test_uom_create_assigns_organization_id(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/uoms', [
            'measure_name' => 'crate',
            'full_name' => 'Crate',
            'conversion_factor' => 12,
            'uom_type' => 'piece',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('organization_id', $admin->organization_id);
    }

    public function test_uom_delete_blocked_when_linked_to_products(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $uom = Uom::query()->where('organization_id', $admin->organization_id)->firstOrFail();
        $product = Product::query()->where('organization_id', $admin->organization_id)->firstOrFail();
        $product->update(['unit_id' => $uom->id]);

        $this->deleteJson("/api/v1/uoms/{$uom->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['uom']);

        $this->assertDatabaseHas('uoms', ['id' => $uom->id]);
    }

    public function test_categories_and_subcategories_are_scoped_per_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgB = $this->createOrganization('CATREF4', 'Catalog Reference Org Four');

        $categoryB = Category::create([
            'category_name' => 'Org B Category',
            'organization_id' => $orgB->id,
            'created_by' => $admin->id,
        ]);
        SubCategory::create([
            'category_id' => $categoryB->id,
            'subcategory_name' => 'Org B Sub',
            'organization_id' => $orgB->id,
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $categories = $this->getJson('/api/v1/categories?per_page=100')->assertOk();
        $names = collect($categories->json('data'))->pluck('category_name');
        $this->assertFalse($names->contains('Org B Category'));

        $subcategories = $this->getJson('/api/v1/sub-categories?per_page=100')->assertOk();
        $subNames = collect($subcategories->json('data'))->pluck('subcategory_name');
        $this->assertFalse($subNames->contains('Org B Sub'));
    }

    public function test_cannot_delete_sub_category_used_by_products(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()->where('organization_id', $admin->organization_id)->firstOrFail();
        $subCategory = SubCategory::query()->findOrFail($product->subcategory_id);

        $this->deleteJson("/api/v1/sub-categories/{$subCategory->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subcategory']);

        $this->assertDatabaseHas('sub_categories', ['id' => $subCategory->id]);
    }

    public function test_cannot_delete_category_with_sub_categories(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $subCategory = SubCategory::query()
            ->where('organization_id', $admin->organization_id)
            ->firstOrFail();
        $category = Category::query()->findOrFail($subCategory->category_id);

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_subcategories_can_be_searched_by_name(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $sub = SubCategory::query()->firstOrFail();
        $needle = mb_substr((string) $sub->subcategory_name, 0, 4);
        $this->assertNotSame('', $needle);

        $res = $this->getJson('/api/v1/sub-categories?per_page=50&q='.urlencode($needle));
        $res->assertOk();

        $names = collect($res->json('data'))->pluck('subcategory_name');
        $this->assertTrue($names->contains($sub->subcategory_name));
    }

    protected function createOrganization(string $companyCode, string $orgName): Organization
    {
        return Organization::create([
            'company_code' => $companyCode,
            'org_name' => $orgName,
            'org_email' => strtolower($companyCode).'@test.com',
            'primary_tel' => '0700444333',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
    }
}
