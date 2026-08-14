<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Auth\RoleTemplateService;
use App\Services\Erp\ModuleRegistry;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrixService::ensure();
    }

    public function test_production_roles_include_cashier_and_dispatch_coordinator(): void
    {
        app(RoleTemplateService::class)->ensureAllRoles();

        $this->assertNotNull(Role::query()->where('role_name', 'Cashier')->first());
        $this->assertNotNull(Role::query()->where('role_name', 'Dispatch Coordinator')->first());
        $this->assertNotNull(Role::query()->where('role_name', 'Warehouse Clerk')->first());

        $cashier = Role::query()->where('role_name', 'Cashier')->firstOrFail();
        $this->assertTrue(
            Permission::query()
                ->whereIn('permission_code', [
                    'pos.terminal.view',
                    'pos.checkout.create',
                    'sales.collect_payment.create',
                ])
                ->whereIn('id', function ($query) use ($cashier) {
                    $query->select('permission_id')
                        ->from('role_permissions')
                        ->where('role_id', $cashier->id);
                })
                ->count() === 3,
        );
    }

    public function test_supermarket_profile_recommends_cashier_not_mobile_rep(): void
    {
        $modules = ModuleRegistry::cascade(config('erp.profiles.supermarket.modules'));
        $roles = app(RoleTemplateService::class)->recommendedForProfile('supermarket', $modules);
        $names = array_column($roles, 'role_name');

        $this->assertContains('Cashier', $names);
        $this->assertNotContains('Mobile Sales Rep', $names);
    }

    public function test_distribution_profile_recommends_dispatch_and_driver(): void
    {
        $modules = ModuleRegistry::cascade(config('erp.profiles.distribution.modules'));
        $roles = app(RoleTemplateService::class)->recommendedForProfile('distribution', $modules);
        $names = array_column($roles, 'role_name');

        $this->assertContains('Dispatch Coordinator', $names);
        $this->assertContains('Driver', $names);
    }

    public function test_custom_profile_adds_roles_from_enabled_modules(): void
    {
        $modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.backend' => true,
            'sales.pos' => true,
            'inventory' => true,
            'admin' => true,
        ]);

        $roles = app(RoleTemplateService::class)->recommendedForProfile('custom', $modules);
        $names = array_column($roles, 'role_name');

        $this->assertContains('Cashier', $names);
        $this->assertContains('Warehouse Clerk', $names);
        $this->assertNotContains('Dispatch Coordinator', $names);
    }

    public function test_hotel_bar_profile_recommends_hospitality_roles_only(): void
    {
        $modules = ModuleRegistry::cascade(config('erp.profiles.hotel_bar.modules'));
        $roles = app(RoleTemplateService::class)->recommendedForProfile('hotel_bar', $modules);
        $names = array_column($roles, 'role_name');

        $this->assertContains('Hotel Cashier', $names);
        $this->assertContains('Front Desk', $names);
        $this->assertContains('Hotel Manager', $names);
        $this->assertContains('Hotel Store Keeper', $names);
        $this->assertNotContains('Cashier', $names);
        $this->assertNotContains('Driver', $names);
        $this->assertNotContains('Mobile Sales Rep', $names);
        $this->assertNotContains('Dispatch Coordinator', $names);
        $this->assertNotContains('Branch Manager', $names);
    }

    public function test_field_manager_template_exists_separately_from_branch_manager(): void
    {
        app(RoleTemplateService::class)->ensureAllRoles();

        $this->assertNotNull(Role::query()->where('role_name', 'Branch Manager')->first());
        $this->assertNotNull(Role::query()->where('role_name', 'Field Manager')->first());
    }
}
