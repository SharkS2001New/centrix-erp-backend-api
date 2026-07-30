<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileLoadingSheetTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected RouteModel $route;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->route = RouteModel::firstOrFail();
        Sanctum::actingAs($this->user);

        $this->configureMobileWithoutDistribution();
    }

    protected function configureMobileWithoutDistribution(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = false;
        $modules['sales.mobile'] = true;
        $modules['sales.backend'] = true;

        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mobile_orders' => true,
        ]);

        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
    }

    public function test_mobile_loading_sheets_list_and_detail(): void
    {
        $template = Sale::query()->where('channel', 'mobile')->first();
        if (! $template) {
            $template = Sale::query()->firstOrFail();
        }

        $sale = Sale::create([
            'order_num' => 94001,
            'branch_id' => $this->user->branch_id ?? $template->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->user->id,
            'customer_num' => $template->customer_num,
            'route_id' => $this->route->id,
            'status' => 'processed',
            'total_vat' => 100,
            'order_total' => 1500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $listDate = $sale->created_at->format('Y-m-d');

        $this->getJson('/api/v1/sales/mobile-loading-sheets')
            ->assertOk()
            ->assertJsonFragment([
                'route_id' => $this->route->id,
                'list_date' => $listDate,
            ]);

        $detail = $this->getJson('/api/v1/sales/mobile-loading-sheets/detail?'.http_build_query([
            'route_id' => $this->route->id,
            'list_date' => $listDate,
        ]))
            ->assertOk()
            ->assertJsonPath('loading_list.route_id', $this->route->id)
            ->assertJsonPath('loading_list.list_date', $listDate)
            ->json();

        $this->assertGreaterThanOrEqual(1, (int) ($detail['loading_list']['order_count'] ?? 0));
        $orderNums = collect($detail['orders'] ?? [])->pluck('order_num')->all();
        $this->assertContains($sale->order_num, $orderNums);
    }

    public function test_mobile_picking_sheets_list_and_detail(): void
    {
        $template = Sale::query()->where('channel', 'mobile')->first();
        if (! $template) {
            $template = Sale::query()->firstOrFail();
        }

        $sale = Sale::create([
            'order_num' => 94002,
            'branch_id' => $this->user->branch_id ?? $template->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $this->user->id,
            'customer_num' => $template->customer_num,
            'route_id' => $this->route->id,
            'status' => 'processed',
            'total_vat' => 100,
            'order_total' => 1500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $listDate = $sale->created_at->format('Y-m-d');

        $this->getJson('/api/v1/sales/mobile-picking-sheets')
            ->assertOk()
            ->assertJsonFragment([
                'route_id' => $this->route->id,
                'list_date' => $listDate,
            ]);

        $detailResponse = $this->getJson('/api/v1/sales/mobile-picking-sheets/detail?'.http_build_query([
            'route_id' => $this->route->id,
            'list_date' => $listDate,
        ]))
            ->assertOk()
            ->assertJsonPath('picking_list.route_id', $this->route->id)
            ->assertJsonPath('picking_list.list_date', $listDate);

        $detail = $detailResponse->json();
        $this->assertIsArray($detail['picking_list']['lines'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) ($detail['picking_list']['order_count'] ?? 0));
        $orderNums = collect($detail['orders'] ?? [])->pluck('order_num')->all();
        $this->assertContains($sale->order_num, $orderNums);
    }

    public function test_mobile_loading_sheets_blocked_when_distribution_enabled(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update(['enabled_modules' => $modules]);

        $this->getJson('/api/v1/sales/mobile-loading-sheets')
            ->assertForbidden();
    }

    public function test_mobile_picking_sheets_blocked_when_distribution_enabled(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update(['enabled_modules' => $modules]);

        $this->getJson('/api/v1/sales/mobile-picking-sheets')
            ->assertForbidden();
    }
}
