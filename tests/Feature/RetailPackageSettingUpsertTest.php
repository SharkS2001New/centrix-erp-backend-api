<?php

namespace Tests\Feature;

use App\Models\PlatformSubscription;
use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RetailPackageSettingUpsertTest extends TestCase
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

    public function test_store_upserts_when_product_code_already_has_retail_package(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($admin);
        Sanctum::actingAs($admin);

        $code = 'RPS-UPSERT-'.uniqid();
        Product::query()->create([
            'product_code' => $code,
            'product_name' => 'Retail upsert target',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 100,
            'vat_id' => 1,
            'organization_id' => $admin->organization_id,
            'created_by' => $admin->id,
            'sell_on_retail' => true,
        ]);

        RetailPackageSetting::query()->create([
            'product_code' => $code,
            'max_qty_measure' => 5,
            'markup_price' => 10,
            'min_uom_measure' => null,
            'wholesale_qty_measure' => 0,
            'wholesale_markup_price' => 0,
            'max_uom_measure' => null,
            'pricing_tiers' => [
                ['min_qty' => 1, 'max_qty' => 5, 'markup' => 10],
            ],
        ]);

        $response = $this->postJson('/api/v1/retail-package-settings', [
            'product_code' => $code,
            'max_qty_measure' => 20,
            'markup_price' => 25,
            'min_uom_measure' => null,
            'wholesale_qty_measure' => 0,
            'wholesale_markup_price' => 0,
            'max_uom_measure' => null,
            'pricing_tiers' => [
                ['min_qty' => 1, 'max_qty' => 20, 'markup' => 25],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('product_code', $code)
            ->assertJsonPath('markup_price', 25);

        $this->assertSame(1, RetailPackageSetting::query()->where('product_code', $code)->count());
        $this->assertEqualsWithDelta(
            25.0,
            (float) RetailPackageSetting::query()->where('product_code', $code)->value('markup_price'),
            0.0001,
        );
    }
}
