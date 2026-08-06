<?php

namespace Tests\Feature;

use App\Models\PlatformSubscription;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ProductInlinePriceUpdateTest extends TestCase
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

    public function test_update_unit_price_without_sending_vat_id(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($user);
        Sanctum::actingAs($user);

        $code = 'PRICE-INLINE-'.uniqid();
        Product::query()->create([
            'product_code' => $code,
            'product_name' => 'Inline Price Item',
            'subcategory_id' => 1,
            'unit_id' => 1,
            'unit_price' => 1000,
            'vat_id' => 1,
            'organization_id' => $user->organization_id,
            'created_by' => $user->id,
        ]);

        $this->putJson('/api/v1/products/'.rawurlencode($code), [
            'unit_price' => 1900,
        ])
            ->assertOk()
            ->assertJsonPath('unit_price', 1900)
            ->assertJsonPath('vat_id', 1);

        $this->assertDatabaseHas('products', [
            'product_code' => $code,
            'unit_price' => 1900,
            'vat_id' => 1,
        ]);
    }
}
