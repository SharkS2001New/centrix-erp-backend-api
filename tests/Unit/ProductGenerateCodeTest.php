<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ProductGenerateCodeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_generate_next_product_code_returns_unique_six_digit_sku(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        $code = Product::generateNextProductCode($orgId);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertGreaterThanOrEqual(100000, (int) $code);
        $this->assertLessThanOrEqual(999999, (int) $code);
    }

    public function test_generate_next_increments_after_existing_six_digit_codes(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        Product::create(array_merge($this->productTemplate($orgId), [
            'product_code' => '100042',
            'organization_id' => $orgId,
            'created_by' => $admin->id,
        ]));

        $code = Product::generateNextProductCode($orgId);

        $this->assertSame('100043', $code);
    }

    public function test_legacy_prd_codes_do_not_block_six_digit_sequence(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        Product::create(array_merge($this->productTemplate($orgId), [
            'product_code' => 'PRD#0001',
            'organization_id' => $orgId,
            'created_by' => $admin->id,
        ]));

        $code = Product::generateNextProductCode($orgId);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    /** @return array<string, mixed> */
    protected function productTemplate(int $organizationId): array
    {
        $adminId = User::where('username', 'admin')->value('id');

        $sub = SubCategory::query()->where('organization_id', $organizationId)->first()
            ?? SubCategory::create([
                'category_id' => Category::create([
                    'category_name' => 'Gen Code Category',
                    'organization_id' => $organizationId,
                    'created_by' => $adminId,
                ])->id,
                'subcategory_name' => 'Gen Code Sub',
                'organization_id' => $organizationId,
                'created_by' => $adminId,
            ]);

        $uom = Uom::query()->where('organization_id', $organizationId)->first()
            ?? Uom::create([
                'conversion_factor' => 1,
                'full_name' => 'Piece',
                'measure_name' => 'pc',
                'uom_type' => 'piece',
                'organization_id' => $organizationId,
                'created_by' => $adminId,
            ]);

        $vat = Vat::query()->where('organization_id', $organizationId)->first()
            ?? Vat::create([
                'vat_code' => 'GC'.$organizationId,
                'vat_name' => 'Gen Code VAT',
                'vat_percentage' => 16,
                'organization_id' => $organizationId,
                'created_by' => $adminId,
            ]);

        return [
            'product_name' => 'Generated SKU Seed',
            'subcategory_id' => $sub->id,
            'unit_id' => $uom->id,
            'unit_price' => 100,
            'vat_id' => $vat->id,
            'branch_id' => Branch::query()->where('organization_id', $organizationId)->value('id')
                ?? Branch::query()->value('id'),
        ];
    }
}
