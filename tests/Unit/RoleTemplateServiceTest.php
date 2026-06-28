<?php

namespace Tests\Unit;

use App\Services\Auth\RoleTemplateService;
use App\Services\Erp\ModuleRegistry;
use Tests\TestCase;

class RoleTemplateServiceTest extends TestCase
{
    public function test_distribution_profile_includes_dispatch_coordinator(): void
    {
        $modules = ModuleRegistry::cascade(config('erp.profiles.distribution.modules'));
        $roles = app(RoleTemplateService::class)->recommendedForProfile('distribution', $modules);
        $names = array_column($roles, 'role_name');

        $this->assertContains('Dispatch Coordinator', $names);
    }

    public function test_custom_profile_excludes_cashier_without_pos(): void
    {
        $modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.backend' => true,
            'inventory' => true,
        ]);

        $roles = app(RoleTemplateService::class)->recommendedForProfile('custom', $modules);
        $names = array_column($roles, 'role_name');

        $this->assertNotContains('Cashier', $names);
        $this->assertContains('Warehouse Clerk', $names);
    }
}
