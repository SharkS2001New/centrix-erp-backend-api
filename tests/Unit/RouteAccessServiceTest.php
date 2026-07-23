<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Fulfillment\RouteAccessService;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RouteAccessServiceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_list_active_routes_is_scoped_to_user_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = Organization::findOrFail($admin->organization_id);

        $orgB = Organization::create([
            'company_code' => 'RAS'.substr(uniqid(), -4),
            'org_name' => 'Route Access Tenant',
            'org_email' => 'route-access@example.com',
            'primary_tel' => '0700000201',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $orgA->enabled_modules,
            'module_settings' => $orgA->module_settings,
        ]);

        $foreignRoute = RouteModel::create([
            'organization_id' => $orgB->id,
            'route_name' => 'Foreign Route '.uniqid(),
            'route_markup_price' => 0,
            'direction' => 'east',
            'is_active' => true,
        ]);

        $routes = app(RouteAccessService::class)->listActiveForUser($admin);

        $this->assertFalse($routes->pluck('id')->contains($foreignRoute->id));
        $this->assertTrue($routes->every(
            fn (RouteModel $route) => (int) $route->organization_id === (int) $admin->organization_id,
        ));
    }

    public function test_branch_limited_user_can_access_org_shared_route(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->assertSame('branch', $cashier->access_scope);

        $shared = RouteModel::create([
            'organization_id' => $cashier->organization_id,
            'branch_id' => null,
            'route_name' => 'Shared Route '.uniqid(),
            'route_markup_price' => 0,
            'direction' => 'north',
            'is_active' => true,
        ]);

        $service = app(RouteAccessService::class);
        $found = $service->findForUser($cashier, (int) $shared->id);

        $this->assertNotNull($found);
        $this->assertSame((int) $shared->id, (int) $found->id);
    }

    public function test_assert_accessible_rejects_foreign_organization_route(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = Organization::findOrFail($admin->organization_id);

        $orgB = Organization::create([
            'company_code' => 'RAR'.substr(uniqid(), -4),
            'org_name' => 'Route Reject Tenant',
            'org_email' => 'route-reject@example.com',
            'primary_tel' => '0700000202',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $orgA->enabled_modules,
            'module_settings' => $orgA->module_settings,
        ]);

        $foreignRoute = RouteModel::create([
            'organization_id' => $orgB->id,
            'route_name' => 'Reject Route '.uniqid(),
            'route_markup_price' => 0,
            'direction' => 'west',
            'is_active' => true,
        ]);

        $service = app(RouteAccessService::class);

        try {
            $service->assertAccessible($admin, $foreignRoute->id, 'route_id');
            $this->fail('Expected validation exception for foreign route.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('route_id', $exception->errors());
        }

        $this->expectException(InvalidArgumentException::class);
        $service->assertAccessible($admin, $foreignRoute->id);
    }
}
