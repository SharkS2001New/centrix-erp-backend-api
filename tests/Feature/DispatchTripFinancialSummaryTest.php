<?php

namespace Tests\Feature;

use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\Product;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class DispatchTripFinancialSummaryTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDistributionModules(User $user): void
    {
        $org = Organization::findOrFail($user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update(['enabled_modules' => $modules]);
    }

    public function test_trip_detail_includes_financial_summary(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $route = RouteModel::query()->firstOrFail();
        $product = Product::query()->firstOrFail();
        $product->update(['last_cost_price' => 300]);

        $sale = Sale::create([
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'route_id' => $route->id,
            'channel' => 'mobile',
            'status' => 'processed',
            'order_total' => 1000,
            'total_vat' => 100,
            'amount_paid' => 0,
            'cashier_id' => $admin->id,
        ]);
        $sale->items()->create([
            'product_code' => $product->product_code,
            'line_no' => 1,
            'quantity' => 2,
            'selling_price' => 500,
            'amount' => 1000,
        ]);

        $trip = DispatchTrip::create([
            'branch_id' => $admin->branch_id,
            'trip_code' => 'TRIP-FIN-001',
            'route_id' => $route->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $trip->sales()->attach([$sale->id => ['stop_seq' => 1]]);

        $res = $this->getJson("/api/v1/dispatch-trips/{$trip->id}");
        $res->assertOk();
        $res->assertJsonPath('financial_summary.order_count', 1);
        $res->assertJsonPath('financial_summary.total_amount', 1000);
        $res->assertJsonPath('financial_summary.total_profit', 300);
        $res->assertJsonPath('financial_summary.profit_margin_percent', 33.3);
    }

    public function test_trip_index_includes_financial_summary(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->enableDistributionModules($admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dispatch-trips?per_page=5')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'financial_summary' => [
                            'order_count',
                            'total_amount',
                            'total_profit',
                            'profit_margin_percent',
                        ],
                    ],
                ],
            ]);
    }
}
