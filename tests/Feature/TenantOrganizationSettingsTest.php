<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TenantOrganizationSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_org_admin_can_read_and_update_self_service_settings(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/erp/settings/general')
            ->assertOk()
            ->assertJsonStructure(['general']);

        $this->patchJson('/api/v1/erp/settings/general', [
            'language' => 'sw',
            'document_footer_text' => 'Thank you for your business.',
        ])
            ->assertOk()
            ->assertJsonPath('general.language', 'sw');

        $this->getJson('/api/v1/erp/settings/notifications')->assertOk();
        $this->getJson('/api/v1/erp/settings/security')->assertOk();
        $this->getJson('/api/v1/erp/settings/ai')->assertOk();
    }

    public function test_org_admin_can_update_print_typography_settings(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/general', [
            'print_font_family' => 'arial',
            'print_font_scale' => 'large',
        ])
            ->assertOk()
            ->assertJsonPath('general.print_font_family', 'arial')
            ->assertJsonPath('general.print_font_scale', 'large');
    }

    public function test_org_admin_can_access_module_settings_routes(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/erp/settings/sales')->assertOk();
        $this->getJson('/api/v1/erp/settings/inventory')->assertOk();
        $this->getJson('/api/v1/erp/settings/procurement')->assertOk();
        $this->getJson('/api/v1/erp/settings/finance')->assertOk();
        $this->getJson('/api/v1/erp/settings/hr')->assertOk();
    }

    public function test_org_admin_cannot_change_platform_controlled_sales_keys(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $before = $this->getJson('/api/v1/erp/settings/sales')->assertOk()->json('sales');

        $this->patchJson('/api/v1/erp/settings/sales', [
            'show_checkout_on_create_order' => ! ($before['show_checkout_on_create_order'] ?? true),
            'enable_mobile_orders' => false,
            'stock_deduct_on' => 'trip_load',
            'require_pos_till_float' => true,
            'allow_discounts' => ! ($before['allow_discounts'] ?? false),
        ])->assertOk();

        $after = $this->getJson('/api/v1/erp/settings/sales')->assertOk()->json('sales');

        $this->assertSame((bool) ($before['show_checkout_on_create_order'] ?? true), (bool) $after['show_checkout_on_create_order']);
        $this->assertSame((bool) ($before['enable_mobile_orders'] ?? true), (bool) $after['enable_mobile_orders']);
        $this->assertSame($before['stock_deduct_on'] ?? 'order_completed', $after['stock_deduct_on']);
        $this->assertSame((bool) ($before['require_pos_till_float'] ?? false), (bool) $after['require_pos_till_float']);
        $this->assertNotSame((bool) ($before['allow_discounts'] ?? false), (bool) $after['allow_discounts']);
    }

    public function test_org_admin_cannot_change_platform_controlled_finance_keys(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $before = $this->getJson('/api/v1/erp/settings/finance')->assertOk()->json('finance');

        $this->patchJson('/api/v1/erp/settings/finance', [
            'enable_mpesa_stk' => false,
            'enable_kra_integration' => false,
            'accounting_mode' => $before['accounting_mode'] ?? 'native',
        ])->assertOk();

        $after = $this->getJson('/api/v1/erp/settings/finance')->assertOk()->json('finance');

        $this->assertSame((bool) ($before['enable_mpesa_stk'] ?? true), (bool) ($after['enable_mpesa_stk'] ?? true));
        $this->assertSame((bool) ($before['enable_kra_integration'] ?? true), (bool) ($after['enable_kra_integration'] ?? true));
    }

    public function test_finance_settings_expose_shop_kra_pin_to_org_admin(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $org = \App\Models\Organization::findOrFail($orgAdmin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_integration' => true,
            'enable_kra_device' => true,
            'kra_pin_number' => 'P052177271G',
        ]);
        $org->update(['module_settings' => $settings]);

        $this->getJson('/api/v1/erp/settings/finance')
            ->assertOk()
            ->assertJsonPath('finance.kra_pin_number', 'P052177271G');

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('module_settings.finance.kra_pin_number', '********');
    }

    public function test_org_admin_can_save_finance_settings_when_platform_mpesa_stk_disabled(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $org = \App\Models\Organization::findOrFail($orgAdmin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_mpesa_stk' => false,
            'enable_kra_integration' => true,
            'enable_kra_device' => true,
            'kra_device_ip' => '192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'default_submit_kra' => false,
        ]);
        $org->update(['module_settings' => $settings]);

        $this->patchJson('/api/v1/erp/settings/finance', [
            'enable_kra_device' => true,
            'kra_device_ip' => '192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'default_submit_kra' => true,
            'mpesa' => [
                'enable_stk_push' => true,
                'env' => 'sandbox',
                'consumer_key' => 'ignored-key',
                'shortcode' => '600000',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('finance.default_submit_kra', true)
            ->assertJsonPath('finance.kra_pin_number', 'P052177271G');

        $org->refresh();
        $this->assertTrue((bool) ($org->module_settings['finance']['default_submit_kra'] ?? false));
        $this->assertSame('P052177271G', $org->module_settings['finance']['kra_pin_number'] ?? null);
        $this->assertNotSame('ignored-key', $org->module_settings['finance']['mpesa']['consumer_key'] ?? null);
    }

    public function test_cashier_cannot_access_organization_settings(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/erp/settings/sales')->assertForbidden();
        $this->getJson('/api/v1/erp/settings/general')->assertForbidden();
    }
}
