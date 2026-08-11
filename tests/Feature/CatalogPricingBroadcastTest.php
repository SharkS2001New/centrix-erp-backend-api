<?php

namespace Tests\Feature;

use App\Events\OrgCatalogPricingUpdated;
use App\Models\InAppNotification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CatalogPricingBroadcastTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_product_unit_price_update_broadcasts_to_organization_channel(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::fake();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->orderBy('product_code')
            ->first();

        $this->assertNotNull($product);

        $nextPrice = round(((float) $product->unit_price) + 1.5, 2);

        $response = $this->putJson('/api/v1/products/'.$product->product_code, [
            'unit_price' => $nextPrice,
        ]);
        $response->assertOk();

        Broadcast::assertBroadcasted(OrgCatalogPricingUpdated::class, function (OrgCatalogPricingUpdated $event) use ($admin, $product) {
            return $event->organizationId === (int) $admin->organization_id
                && ($event->payload['reason'] ?? null) === 'product_price'
                && ($event->payload['product_code'] ?? null) === $product->product_code;
        });
    }

    public function test_product_price_update_creates_in_app_notifications_for_sales_users_and_actor(): void
    {
        config(['broadcasting.default' => 'null']);

        $admin = User::where('username', 'admin')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();

        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->orderBy('product_code')
            ->first();
        $this->assertNotNull($product);

        $beforeCashier = InAppNotification::query()
            ->where('user_id', $cashier->id)
            ->where('type', 'catalog_pricing')
            ->count();
        $beforeAdmin = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('type', 'catalog_pricing')
            ->count();

        app(\App\Services\Catalog\CatalogPricingBroadcastService::class)->notifyProductPriceChanged(
            (int) $admin->organization_id,
            (string) $product->product_code,
            $product->product_name,
            (int) $admin->id,
        );

        $afterCashier = InAppNotification::query()
            ->where('user_id', $cashier->id)
            ->where('type', 'catalog_pricing')
            ->count();
        $this->assertGreaterThan($beforeCashier, $afterCashier);

        $latest = InAppNotification::query()
            ->where('user_id', $cashier->id)
            ->where('type', 'catalog_pricing')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($latest);
        $this->assertStringContainsString('Price updated', (string) $latest->message);
        $this->assertSame('/pos', $latest->action_url);

        // Updater also receives the alert so they can confirm it fired.
        $afterAdmin = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('type', 'catalog_pricing')
            ->count();
        $this->assertGreaterThan($beforeAdmin, $afterAdmin);
    }

    public function test_product_update_without_price_change_does_not_broadcast(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::fake();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->orderBy('product_code')
            ->first();

        $this->assertNotNull($product);

        $response = $this->putJson('/api/v1/products/'.$product->product_code, [
            'unit_price' => (float) $product->unit_price,
            'product_name' => $product->product_name,
        ]);
        $response->assertOk();

        Broadcast::assertNotBroadcasted(OrgCatalogPricingUpdated::class);
    }
}
