<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\Sales\MobileSalesService;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CompletedSalesCacheTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_immutable_completed_sale_detail_is_cached_for_web_and_mobile(): void
    {
        config(['completed_sales_cache.enabled' => true]);

        $rep = $this->makeMobileUser();
        $sale = Sale::query()->where('channel', 'mobile')->firstOrFail();
        $sale->forceFill([
            'cashier_id' => $rep->id,
            'route_id' => $rep->assigned_route_id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'created_at' => now()->subDays(2),
        ])->saveQuietly();

        $cache = app(CompletedSalesCacheService::class);
        $this->assertTrue($cache->isImmutableSale($sale->fresh()));

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/sales/{$sale->id}")
            ->assertOk();

        $orgId = (int) $sale->organization_id;
        $this->assertNotNull($cache->getSaleDetail($orgId, (int) $sale->id, 'web'));

        $organization = \App\Models\Organization::query()->findOrFail($orgId);
        $warmed = $cache->warmOrganizationDate($organization, now()->subDays(2));
        $this->assertGreaterThan(0, $warmed);

        $sale->load(['items.product', 'customer', 'cashier']);
        $mobilePayload = app(MobileSalesService::class)->buildCachedOrderDetail($sale);
        $cache->putSaleDetail($orgId, (int) $sale->id, 'mobile', $mobilePayload);
        $this->assertNotNull($cache->getSaleDetail($orgId, (int) $sale->id, 'mobile'));
    }

    public function test_sale_update_invalidates_completed_sale_cache(): void
    {
        config(['completed_sales_cache.enabled' => true]);

        $template = Sale::query()->where('channel', 'mobile')->firstOrFail();
        $sale = Sale::query()->create([
            'order_num' => (int) (Sale::query()->max('order_num') ?? 0) + 902,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $template->cashier_id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 100,
            'total_vat' => 0,
        ]);
        $sale->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $cache = app(CompletedSalesCacheService::class);
        $orgId = (int) $template->organization_id;
        $cache->putSaleDetail($orgId, (int) $sale->id, 'web', ['id' => $sale->id, 'order_total' => 100]);
        $cache->putSaleDetail($orgId, (int) $sale->id, 'mobile', ['id' => $sale->id, 'order_total' => 100]);

        $sale->update(['order_total' => 120]);

        $this->assertNull($cache->getSaleDetail($orgId, (int) $sale->id, 'web'));
        $this->assertNull($cache->getSaleDetail($orgId, (int) $sale->id, 'mobile'));
    }

    protected function makeMobileUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);

        return User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_cache_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Rep',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'mobile_order_scope' => 'route_only',
            'assigned_route_id' => $routeId,
            'is_active' => true,
        ], $overrides));
    }

    protected function loginMobile(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_CACHE_'.uniqid(),
            'login_channel' => 'mobile',
        ])->assertOk()->json('token');
    }
}
