<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ShopDebtorsSalesIndexTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::where('username', 'admin')->first();
        if ($admin?->organization_id) {
            PlatformSubscription::query()->firstOrCreate(
                ['organization_id' => $admin->organization_id],
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
    }

    public function test_shop_debtors_lists_unpaid_debtor_orders_and_excludes_route_and_mobile(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $suffix = random_int(100000000, 199999999);
        $debtorNum = $suffix;
        $routeNum = $suffix + 1;

        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $debtorNum,
            'customer_name' => 'Shop Debtor '.$suffix,
            'customer_type' => 'debtor',
            'created_by' => $admin->id,
        ]);
        Customer::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'customer_num' => $routeNum,
            'customer_name' => 'Route Customer '.$suffix,
            'customer_type' => 'route',
            'created_by' => $admin->id,
        ]);

        $base = [
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 1500,
            'amount_paid' => 0,
            'archived' => 0,
            'created_at' => now(),
        ];

        $shopUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $debtorNum,
        ]));
        $shopPaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 1,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $debtorNum,
            'status' => 'paid',
            'payment_status' => 'paid',
            'amount_paid' => 1500,
        ]));
        $routeUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 2,
            'channel' => 'backend',
            'order_source' => 'backend',
            'customer_num' => $routeNum,
            'route_id' => 1,
        ]));
        $mobileUnpaid = Sale::query()->create(array_merge($base, [
            'order_num' => $suffix + 3,
            'channel' => 'mobile',
            'order_source' => 'mobile',
            'customer_num' => $debtorNum,
        ]));

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $res = $this->getJson(
            "/api/v1/sales?shop_debtors=1&from_date={$from}&to_date={$to}&per_page=200",
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($shopUnpaid->id, $ids);
        $this->assertNotContains($shopPaid->id, $ids);
        $this->assertNotContains($routeUnpaid->id, $ids);
        $this->assertNotContains($mobileUnpaid->id, $ids);
    }
}
