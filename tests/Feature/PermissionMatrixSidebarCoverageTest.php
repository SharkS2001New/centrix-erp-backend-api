<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Erp\PermissionMatrixService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PermissionMatrixSidebarCoverageTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function seedLicense(User $user): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_inventory_analytics_appears_when_only_parent_inventory_module_is_on(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);

        $org = Organization::findOrFail($admin->organization_id);
        $org->update([
            'enabled_modules' => [
                'admin' => true,
                'inventory' => true,
                // Explicitly off — sidebar still cascades from parent inventory.
                'inventory.dashboard' => false,
                'sales.dashboard' => false,
            ],
        ]);

        $gate = app(ErpContext::class)->gateForOrganization($org->fresh());
        $this->assertTrue($gate->enabled('inventory'));
        $this->assertFalse($gate->enabled('inventory.dashboard'));

        $apps = PermissionMatrixService::applicationsGroupedForUi($gate);
        $dashboard = collect($apps)
            ->flatMap(fn (array $app) => $app['modules'])
            ->firstWhere('module', 'dashboard');

        $this->assertNotNull($dashboard, 'Dashboard group should appear when inventory parent is enabled');
        $labels = collect($dashboard['features'])->pluck('label')->all();
        $this->assertContains('Inventory analytics', $labels);
        $this->assertContains('Business summary', $labels);
    }

    public function test_permission_matrix_includes_sidebar_report_and_notification_permissions(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);
        Sanctum::actingAs($admin);

        PermissionMatrixService::ensure();

        $res = $this->getJson('/api/v1/roles/permissions/matrix')->assertOk();
        $codes = collect($res->json('applications'))
            ->flatMap(fn (array $app) => $app['modules'] ?? [])
            ->flatMap(fn (array $module) => $module['features'] ?? [])
            ->flatMap(fn (array $feature) => $feature['permissions'] ?? [])
            ->pluck('permission_code');

        $this->assertTrue($codes->contains('dashboard.inventory.view'));
        $this->assertTrue($codes->contains('reports.sales_by_product.view'));
        $this->assertTrue($codes->contains('reports.low_stock.view'));
        $this->assertTrue($codes->contains('admin.notifications.view'));
    }
}
