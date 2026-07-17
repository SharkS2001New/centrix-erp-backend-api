<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformOrganizationSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_read_and_update_tenant_settings_when_acting_as_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'SETORG',
            'org_name' => 'Settings Org Ltd',
            'org_email' => 'set@org.com',
            'primary_tel' => '0711000001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'admin' => false],
            'admin_username' => 'set_admin',
            'admin_email' => 'set@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Set Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->getJson("/api/v1/admin/organizations/{$orgId}/settings/general")
            ->assertOk()
            ->assertJsonStructure(['general']);

        $this->patchJson("/api/v1/admin/organizations/{$orgId}/settings/general", [
            'currency' => 'USD',
            'language' => 'en',
        ])->assertOk()
            ->assertJsonPath('general.currency', 'USD');

        $org = Organization::findOrFail($orgId);
        $this->assertSame('USD', $org->module_settings['general']['currency'] ?? null);
    }

    public function test_new_organization_has_tab_workspace_enabled_by_default(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'TABDEF',
            'org_name' => 'Tab Default Org',
            'org_email' => 'tabdef@org.com',
            'primary_tel' => '0711000003',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'admin' => true],
            'admin_username' => 'tabdef_admin',
            'admin_email' => 'tabdef@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Tab Default Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->getJson("/api/v1/admin/organizations/{$orgId}/settings/general")
            ->assertOk()
            ->assertJsonPath('general.enable_tab_workspace', true);

        $orgAdmin = User::where('username', 'tabdef_admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('platform_tab_workspace_enabled', true);
    }

    public function test_super_admin_can_disable_tab_workspace_and_tenant_cannot_override(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'TABORG',
            'org_name' => 'Tab Workspace Org',
            'org_email' => 'tab@org.com',
            'primary_tel' => '0711000002',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'admin' => true],
            'admin_username' => 'tab_admin',
            'admin_email' => 'tab@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Tab Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->patchJson("/api/v1/admin/organizations/{$orgId}/settings/general", [
            'enable_tab_workspace' => false,
        ])->assertOk()
            ->assertJsonPath('general.enable_tab_workspace', false);

        $orgAdmin = User::where('username', 'tab_admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/general', [
            'enable_tab_workspace' => true,
        ])->assertOk();

        $org = Organization::findOrFail($orgId);
        $this->assertFalse($org->module_settings['general']['enable_tab_workspace'] ?? true);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('platform_tab_workspace_enabled', false);
    }

    public function test_tenant_admin_cannot_use_platform_settings_proxy(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/admin/organizations/'.$orgAdmin->organization_id.'/settings/general')
            ->assertForbidden();
    }

    public function test_tenant_org_admin_cannot_update_cart_reservation_via_inventory_settings(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $before = $orgAdmin->organization->module_settings['inventory']['reserve_stock_on_cart'] ?? true;

        $this->patchJson('/api/v1/erp/settings/inventory', [
            'reserve_stock_on_cart' => false,
            'cart_reservation_ttl_minutes' => 5,
        ])->assertOk();

        $orgAdmin->organization->refresh();
        $after = $orgAdmin->organization->module_settings['inventory']['reserve_stock_on_cart'] ?? true;

        $this->assertSame($before, $after);
    }

    public function test_super_admin_can_update_cart_reservation_via_sales_platform(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'CARTRES',
            'org_name' => 'Cart Reservation Org',
            'org_email' => 'cart@org.com',
            'primary_tel' => '0711000099',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true],
            'admin_username' => 'cart_admin',
            'admin_email' => 'cart@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Cart Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'sales_platform' => [
                'reserve_stock_on_cart' => false,
                'cart_reservation_ttl_minutes' => 7,
            ],
        ])->assertOk()
            ->assertJsonPath('sales_platform.reserve_stock_on_cart', false)
            ->assertJsonPath('sales_platform.cart_reservation_ttl_minutes', 7);

        $org = Organization::findOrFail($orgId);
        $this->assertFalse($org->module_settings['inventory']['reserve_stock_on_cart']);
        $this->assertSame(7, $org->module_settings['inventory']['cart_reservation_ttl_minutes']);
    }

    public function test_super_admin_can_configure_order_action_status_gates(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'ACTGATE',
            'org_name' => 'Action Gates Org',
            'org_email' => 'gates@org.com',
            'primary_tel' => '0711000088',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true],
            'admin_username' => 'gates_admin',
            'admin_email' => 'gates@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Gates Admin',
        ])->assertCreated();

        $orgId = (int) $create->json('organization.id');
        $versionBefore = \App\Services\Cache\OrganizationCache::capabilitiesVersion($orgId);

        $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'sales_platform' => [
                'edit_order_statuses' => ['booked', 'unpaid'],
                'print_invoice_statuses' => ['paid', 'completed'],
                'collect_payment_statuses' => ['unpaid'],
                'cancel_order_statuses' => ['unpaid', 'booked'],
                'customer_return_statuses' => ['completed', 'delivered'],
            ],
        ])->assertOk()
            ->assertJsonPath('sales_platform.edit_order_statuses', ['booked', 'unpaid'])
            ->assertJsonPath('sales_platform.print_invoice_statuses', ['paid', 'completed'])
            ->assertJsonPath('sales_platform.collect_payment_statuses', ['unpaid'])
            ->assertJsonPath('sales_platform.cancel_order_statuses', ['unpaid', 'booked'])
            ->assertJsonPath('sales_platform.customer_return_statuses', ['completed', 'delivered']);

        $org = Organization::findOrFail($orgId);
        $this->assertSame(['booked', 'unpaid'], $org->module_settings['sales']['edit_order_statuses']);
        $this->assertSame(['paid', 'completed'], $org->module_settings['sales']['print_invoice_statuses']);
        $this->assertSame(['unpaid'], $org->module_settings['sales']['collect_payment_statuses']);
        $this->assertSame(['unpaid', 'booked'], $org->module_settings['sales']['cancel_order_statuses']);
        $this->assertSame(['completed', 'delivered'], $org->module_settings['sales']['customer_return_statuses']);

        $versionAfter = \App\Services\Cache\OrganizationCache::capabilitiesVersion($orgId);
        $this->assertNotSame($versionBefore, $versionAfter);

        $workflow = \App\Services\Erp\OrderWorkflowService::forGate(
            (new \App\Services\Erp\CapabilityGate($org))->forOrganization($org)
        );
        $this->assertSame(['booked', 'unpaid'], $workflow->editOrderStatuses());
        $this->assertSame(['paid', 'completed'], $workflow->printInvoiceStatuses());
        $this->assertSame(['unpaid'], $workflow->collectPaymentStatuses());
        $this->assertTrue($workflow->isEditableLineStatus('unpaid'));
        $this->assertFalse($workflow->isEditableLineStatus('pending'));
        $this->assertTrue($workflow->isPrintInvoiceStatus('paid'));
        $this->assertFalse($workflow->isPrintInvoiceStatus('unpaid'));
        $this->assertTrue($workflow->isCollectPaymentStatus('unpaid'));
        $this->assertFalse($workflow->isCollectPaymentStatus('delivered'));
        $this->assertTrue($workflow->isCancellableStatus('unpaid'));
        $this->assertFalse($workflow->isCancellableStatus('processed'));
        $this->assertTrue($workflow->isCustomerReturnStatus('completed'));
        $this->assertFalse($workflow->isCustomerReturnStatus('paid'));
    }

    public function test_order_action_status_gates_reject_empty_edit_list(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'ACTBAD',
            'org_name' => 'Action Gates Bad Org',
            'org_email' => 'badgates@org.com',
            'primary_tel' => '0711000077',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true],
            'admin_username' => 'badgates_admin',
            'admin_email' => 'badgates@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Bad Gates Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'sales_platform' => [
                'edit_order_statuses' => [],
            ],
        ])->assertStatus(422);
    }

    public function test_tenant_org_admin_can_use_erp_module_settings_routes(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/erp/settings/sales')->assertOk();
        $this->getJson('/api/v1/erp/settings/general')->assertOk();
    }

    public function test_organization_index_includes_administration_enabled_flag(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/admin/organizations')->assertOk();

        $demo = collect($response->json('data'))->firstWhere('company_code', 'DEMO');
        $this->assertNotNull($demo);
        $this->assertArrayHasKey('administration_enabled', $demo);
    }

    public function test_super_admin_can_list_organizations_when_provisioning_disabled(): void
    {
        config(['erp.allow_org_provisioning' => false]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/organizations')->assertOk();
    }
}
