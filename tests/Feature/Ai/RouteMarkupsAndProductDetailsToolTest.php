<?php

namespace Tests\Feature\Ai;

use App\Models\RouteModel;
use App\Models\User;
use App\Services\Ai\Tools\GetProductDetailsTool;
use App\Services\Ai\Tools\GetRouteMarkupsTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RouteMarkupsAndProductDetailsToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_get_route_markups_lists_route_markup_prices(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        RouteModel::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'route_name' => 'AI Markup Route A',
            'route_markup_price' => 25,
            'is_active' => true,
        ]);
        RouteModel::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'route_name' => 'AI Markup Route B',
            'route_markup_price' => 0,
            'is_active' => true,
        ]);

        $result = app(GetRouteMarkupsTool::class)->execute($admin, []);

        $this->assertGreaterThanOrEqual(2, (int) ($result['route_count'] ?? 0));
        $names = collect($result['routes'] ?? [])->pluck('route_name')->all();
        $this->assertContains('AI Markup Route A', $names);
        $rowA = collect($result['routes'])->firstWhere('route_name', 'AI Markup Route A');
        $this->assertSame(25.0, (float) ($rowA['route_markup_price'] ?? 0));
    }

    public function test_get_product_details_returns_uom_for_named_product(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $existing = \App\Models\Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->whereNotNull('unit_id')
            ->orderBy('id')
            ->first();

        if (! $existing) {
            $this->markTestSkipped('No product with UoM in demo seed.');
        }

        $result = app(GetProductDetailsTool::class)->execute($admin, [
            'query' => (string) $existing->product_name,
        ]);

        $this->assertFalse((bool) ($result['error'] ?? false));
        $this->assertNotEmpty($result['measurements'] ?? null);
        $this->assertTrue((bool) ($result['measurements']['configured'] ?? false));
        $this->assertNotEmpty($result['measurements']['conversion_meaning'] ?? null);
    }
}
