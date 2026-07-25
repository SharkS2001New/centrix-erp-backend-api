<?php

namespace Tests\Feature;

use App\Models\PlatformSubscription;
use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ProductDeleteTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function ensureActiveSubscription(User $user): void
    {
        if (! $user->organization_id) {
            return;
        }

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
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

    public function test_delete_active_product_soft_deletes(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($admin);
        Sanctum::actingAs($admin);

        $code = 'DEL-ACTIVE-'.uniqid();
        Product::query()->create([
            'product_code' => $code,
            'product_name' => 'Active delete target',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 10,
            'vat_id' => 1,
            'organization_id' => $admin->organization_id,
            'created_by' => $admin->id,
        ]);

        $this->deleteJson('/api/v1/products/'.rawurlencode($code))
            ->assertNoContent();

        $this->assertSoftDeleted('products', [
            'product_code' => $code,
            'organization_id' => $admin->organization_id,
        ]);
    }

    public function test_delete_inactive_product_force_deletes(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($admin);
        Sanctum::actingAs($admin);

        $code = 'DEL-INACTIVE-'.uniqid();
        $product = Product::query()->create([
            'product_code' => $code,
            'product_name' => 'Inactive delete target',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 10,
            'vat_id' => 1,
            'organization_id' => $admin->organization_id,
            'created_by' => $admin->id,
        ]);
        $product->delete();

        RetailPackageSetting::query()->create([
            'product_code' => $code,
            'max_qty_measure' => 1,
            'markup_price' => 0,
            'min_uom_measure' => 'pcs',
            'wholesale_qty_measure' => 0,
            'wholesale_markup_price' => 0,
            'max_uom_measure' => 'pcs',
        ]);

        $this->assertNotNull(Product::withTrashed()->where('product_code', $code)->first());

        $this->deleteJson('/api/v1/products/'.rawurlencode($code))
            ->assertNoContent();

        $this->assertDatabaseMissing('products', [
            'product_code' => $code,
            'organization_id' => $admin->organization_id,
        ]);
        $this->assertDatabaseMissing('retail_package_settings', [
            'product_code' => $code,
        ]);
    }
}
