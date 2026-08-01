<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\WorkspaceResolver;
use Tests\TestCase;

class WorkspaceResolverIndustryTest extends TestCase
{
    public function test_commerce_org_never_sees_hotel_workspaces_even_with_inventory(): void
    {
        $org = new Organization([
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => [
                'sales' => true,
                'sales.pos' => true,
                'sales.backend' => true,
                'inventory' => true,
                'customers_suppliers' => true,
                // Misconfigured hospitality flags must still be industry-blocked.
                'hospitality' => true,
                'hospitality.backend' => true,
                'hospitality.bar_pos' => true,
            ],
        ]);

        $admin = new User([
            'is_admin' => true,
            'is_super_admin' => false,
        ]);

        $ids = array_column(
            app(WorkspaceResolver::class)->availableForUser($admin, new CapabilityGate($org)),
            'id',
        );

        $this->assertNotContains('hotel_bar_pos', $ids);
        $this->assertNotContains('hospitality_backoffice', $ids);
        $this->assertContains('pos', $ids);
        $this->assertContains('backoffice', $ids);
    }

    public function test_hospitality_org_never_sees_retail_pos_backoffice_or_distribution(): void
    {
        $org = new Organization([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => [
                'hospitality' => true,
                'hospitality.backend' => true,
                'hospitality.bar_pos' => true,
                'inventory' => true,
                'customers_suppliers' => true,
                // Misconfigured retail flags must still be industry-blocked.
                'sales' => true,
                'sales.pos' => true,
                'sales.backend' => true,
                'distribution' => true,
            ],
        ]);

        $admin = new User([
            'is_admin' => true,
            'is_super_admin' => false,
        ]);

        $ids = array_column(
            app(WorkspaceResolver::class)->availableForUser($admin, new CapabilityGate($org)),
            'id',
        );

        $this->assertContains('hotel_bar_pos', $ids);
        $this->assertContains('hospitality_backoffice', $ids);
        $this->assertNotContains('pos', $ids);
        $this->assertNotContains('backoffice', $ids);
        $this->assertNotContains('distribution', $ids);
    }

    public function test_inventory_alone_does_not_unlock_hotel_backoffice_on_commerce(): void
    {
        $org = new Organization([
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => [
                'inventory' => true,
                'customers_suppliers' => true,
                'sales' => true,
                'sales.backend' => true,
            ],
        ]);

        $admin = new User([
            'is_admin' => true,
            'is_super_admin' => false,
        ]);

        $ids = array_column(
            app(WorkspaceResolver::class)->availableForUser($admin, new CapabilityGate($org)),
            'id',
        );

        $this->assertContains('backoffice', $ids);
        $this->assertNotContains('hospitality_backoffice', $ids);
    }

    public function test_hotel_workspace_labels(): void
    {
        $this->assertSame('Hotel POS', config('erp_workspaces.hotel_bar_pos.label'));
        $this->assertSame('Hotel Backoffice', config('erp_workspaces.hospitality_backoffice.label'));
    }
}
