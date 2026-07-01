<?php

namespace App\Services;

use App\Models\Organization;
use App\Services\Erp\AdvancedDataImportPageRegistry;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use Illuminate\Validation\ValidationException;

class OrganizationPlatformConfigService
{
    /** @return list<string> */
    public function platformControlledSalesKeys(): array
    {
        return config('erp.platform_controlled.sales', []);
    }

    /** @return list<string> */
    public function platformControlledDistributionKeys(): array
    {
        return config('erp.platform_controlled.distribution', []);
    }

    /** @return list<string> */
    public function platformControlledFinanceKeys(): array
    {
        return config('erp.platform_controlled.finance', []);
    }

    /** @return list<string> */
    public function platformControlledAiKeys(): array
    {
        return config('erp.platform_controlled.ai', []);
    }

    /** @return list<string> */
    public function platformControlledInventoryKeys(): array
    {
        return config('erp.platform_controlled.inventory', []);
    }

    /** @return list<string> */
    public function platformControlledAdminKeys(): array
    {
        return config('erp.platform_controlled.admin', []);
    }

    /**
     * @param  array<string, mixed>  $salesPlatform
     */
    public function applySalesPlatformConfig(Organization $org, array $salesPlatform): Organization
    {
        if ($salesPlatform === []) {
            return $org;
        }

        $gate = app(CapabilityGate::class)->forOrganization($org);
        $workflowService = OrderWorkflowService::forGate($gate);
        $currentSales = $gate->moduleSettings('sales');
        $nextSales = $currentSales;

        foreach ($this->platformControlledSalesKeys() as $key) {
            if (array_key_exists($key, $salesPlatform)) {
                $nextSales[$key] = $salesPlatform[$key];
            }
        }

        if (array_key_exists('stock_deduct_on', $salesPlatform)) {
            $nextSales['stock_deduct_on'] = $this->normalizeStockDeductOn(
                $salesPlatform['stock_deduct_on'],
                (bool) ($salesPlatform['show_checkout_on_create_order'] ?? $nextSales['show_checkout_on_create_order'] ?? true),
                (bool) ($org->enabled_modules['sales.pos'] ?? false),
            );
        }

        if (array_key_exists('order_workflow', $salesPlatform) && is_array($salesPlatform['order_workflow'])) {
            $defaults = config('erp.default_order_workflow', []);
            $nextSales['order_workflow'] = $workflowService->normalize(
                $workflowService->mergeWorkflowConfig($defaults, $salesPlatform['order_workflow']),
            );
        }

        if (array_key_exists('enable_pos_order_edit', $salesPlatform)) {
            $nextSales['enable_pos_order_edit'] = (bool) $salesPlatform['enable_pos_order_edit'];
        }

        if (array_key_exists('enable_backoffice_order_edit', $salesPlatform)) {
            $nextSales['enable_backoffice_order_edit'] = (bool) $salesPlatform['enable_backoffice_order_edit'];
        }

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['sales'] = $nextSales;

        $currentFinance = is_array($moduleSettings['finance'] ?? null) ? $moduleSettings['finance'] : [];
        foreach ($this->platformControlledFinanceKeys() as $key) {
            if (array_key_exists($key, $salesPlatform)) {
                $currentFinance[$key] = (bool) $salesPlatform[$key];
            }
        }
        $moduleSettings['finance'] = $currentFinance;

        $currentAi = is_array($moduleSettings['ai'] ?? null) ? $moduleSettings['ai'] : [];
        foreach ($this->platformControlledAiKeys() as $key) {
            if (array_key_exists($key, $salesPlatform)) {
                $currentAi[$key] = (bool) $salesPlatform[$key];
            }
        }
        if (array_key_exists('enable_ai', $salesPlatform) && ! $salesPlatform['enable_ai']) {
            $currentAi['enabled'] = false;
        }
        $moduleSettings['ai'] = $currentAi;

        $currentAdmin = is_array($moduleSettings['admin'] ?? null) ? $moduleSettings['admin'] : [];
        foreach ($this->platformControlledAdminKeys() as $key) {
            if (! array_key_exists($key, $salesPlatform)) {
                continue;
            }
            if ($key === 'advanced_data_import_pages') {
                $currentAdmin[$key] = AdvancedDataImportPageRegistry::normalizeOverrides($salesPlatform[$key]);

                continue;
            }
            $currentAdmin[$key] = (bool) $salesPlatform[$key];
        }
        $moduleSettings['admin'] = $currentAdmin;

        $currentInventory = is_array($moduleSettings['inventory'] ?? null) ? $moduleSettings['inventory'] : [];
        foreach ($this->platformControlledInventoryKeys() as $key) {
            if (! array_key_exists($key, $salesPlatform)) {
                continue;
            }
            if ($key === 'cart_reservation_ttl_minutes') {
                $currentInventory[$key] = min(15, max(0, (int) $salesPlatform[$key]));

                continue;
            }
            $currentInventory[$key] = (bool) $salesPlatform[$key];
        }
        $moduleSettings['inventory'] = $currentInventory;

        $org->forceFill(['module_settings' => $moduleSettings])->save();

        return $org->fresh();
    }

    /**
     * Default platform sales config for a new tenant.
     *
     * @return array<string, mixed>
     */
    public function defaultSalesPlatformConfig(string $deploymentProfile = 'wholesale_retail'): array
    {
        return [
            'show_checkout_on_create_order' => true,
            'enable_mobile_orders' => ! in_array($deploymentProfile, ['small_shop', 'supermarket'], true),
            'enable_mpesa_stk' => true,
            'enable_kra_integration' => true,
            'enable_ai' => true,
            'enable_advanced_data_import' => false,
            'advanced_data_import_pages' => AdvancedDataImportPageRegistry::defaultEnabledMap(),
            'stock_deduct_on' => [
                'pos' => 'order_created',
                'mobile' => 'order_completed',
                'backend' => 'order_completed',
            ],
            'require_pos_till_float' => false,
            'order_workflow' => config('erp.default_order_workflow', []),
            'enable_pos_order_edit' => false,
            'enable_backoffice_order_edit' => true,
            'reserve_stock_on_cart' => true,
            'cart_reservation_ttl_minutes' => 15,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salesPlatformConfigForOrganization(Organization $org): array
    {
        $gate = app(CapabilityGate::class)->forOrganization($org);
        $sales = $gate->moduleSettings('sales');
        $inventory = $gate->moduleSettings('inventory');
        $finance = $gate->moduleSettings('finance');
        $ai = $gate->moduleSettings('ai');
        $admin = $gate->moduleSettings('admin');
        $workflow = OrderWorkflowService::forGate($gate)->config();
        $importPages = AdvancedDataImportPageRegistry::resolveEnabledMap(
            is_array($admin['advanced_data_import_pages'] ?? null) ? $admin['advanced_data_import_pages'] : [],
            (bool) ($admin['enable_advanced_data_import'] ?? false),
        );

        return [
            'show_checkout_on_create_order' => (bool) ($sales['show_checkout_on_create_order'] ?? true),
            'enable_mobile_orders' => (bool) ($sales['enable_mobile_orders'] ?? true),
            'enable_mpesa_stk' => (bool) ($finance['enable_mpesa_stk'] ?? true),
            'enable_kra_integration' => (bool) ($finance['enable_kra_integration'] ?? true),
            'enable_ai' => (bool) ($ai['enable_ai'] ?? true),
            'enable_advanced_data_import' => (bool) ($admin['enable_advanced_data_import'] ?? false),
            'advanced_data_import_pages' => $importPages,
            'stock_deduct_on' => (string) ($sales['stock_deduct_on'] ?? 'order_created'),
            'require_pos_till_float' => (bool) ($sales['require_pos_till_float'] ?? false),
            'enable_pos_order_edit' => (bool) ($sales['enable_pos_order_edit'] ?? false),
            'enable_backoffice_order_edit' => (bool) ($sales['enable_backoffice_order_edit'] ?? true),
            'order_workflow' => $workflow,
            'reserve_stock_on_cart' => ($inventory['reserve_stock_on_cart'] ?? true) !== false,
            'cart_reservation_ttl_minutes' => min(
                15,
                max(0, (int) ($inventory['cart_reservation_ttl_minutes'] ?? 15)),
            ),
        ];
    }

    public function mobileOrdersEnabledForOrganization(Organization $org): bool
    {
        return (bool) ($this->salesPlatformConfigForOrganization($org)['enable_mobile_orders'] ?? true);
    }

    /**
     * @param  array<string, bool>  $enabledModules
     * @param  array<string, mixed>  $salesPlatform
     * @return array<string, bool>
     */
    public function reconcileEnabledModules(Organization $org, array $enabledModules, array $salesPlatform = []): array
    {
        $mobileOrders = array_key_exists('enable_mobile_orders', $salesPlatform)
            ? (bool) $salesPlatform['enable_mobile_orders']
            : $this->mobileOrdersEnabledForOrganization($org);

        if (! $mobileOrders) {
            $enabledModules['sales.mobile'] = false;
        } else {
            $enabledModules['sales.mobile'] = true;
        }

        if (($enabledModules['distribution'] ?? false) && ! $mobileOrders) {
            throw ValidationException::withMessages([
                'enabled_modules' => ['Distribution requires mobile orders to be enabled for this organization.'],
            ]);
        }

        if ($enabledModules['sales.pos'] ?? false) {
            $enabledModules = array_merge($enabledModules, [
                'sales' => true,
                'sales.pos' => true,
                'sales.backend' => true,
                'sales.dashboard' => true,
                'sales.reports' => true,
                'inventory' => true,
                'inventory.dashboard' => true,
                'inventory.reports' => true,
                'customers_suppliers' => true,
                'customers_suppliers.reports' => true,
            ]);
        }

        return $enabledModules;
    }

    /**
     * Strip keys tenant managers cannot change via org settings API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerSalesPayload(array $data, ?CapabilityGate $gate = null): array
    {
        foreach ($this->platformControlledSalesKeys() as $key) {
            unset($data[$key]);
        }
        unset($data['order_workflow']);

        if ($gate && ! $gate->mobileSalesEnabled()) {
            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, 'mobile_') || $key === 'enable_mobile_orders') {
                    unset($data[$key]);
                }
            }
        }

        if ($gate && ! $gate->mpesaStkPlatformEnabled()) {
            unset($data['enable_mpesa_amount'], $data['enable_mpesa_code']);
        }

        return $data;
    }

    /**
     * Strip keys tenant managers cannot change via org inventory settings API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerInventoryPayload(array $data): array
    {
        foreach ($this->platformControlledInventoryKeys() as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerDistributionPayload(array $data): array
    {
        foreach ($this->platformControlledDistributionKeys() as $key) {
            unset($data[$key]);
        }
        unset($data['enable_distribution_ops']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerFinancePayload(array $data, ?CapabilityGate $gate = null): array
    {
        foreach ($this->platformControlledFinanceKeys() as $key) {
            unset($data[$key]);
        }

        if (isset($data['enable_kra_device']) && ! $this->kraIntegrationAllowedForPayload($data)) {
            unset($data['enable_kra_device'], $data['kra_device_ip'], $data['kra_serial_number'], $data['kra_pin_number']);
        }

        if (isset($data['mpesa']) && is_array($data['mpesa'])) {
            if ($gate && ! $gate->mpesaStkPlatformEnabled()) {
                unset($data['mpesa']);
            } elseif (! $this->mpesaStkAllowedForPayload($data)) {
                unset($data['mpesa']);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerAiPayload(array $data, CapabilityGate $gate): array
    {
        foreach ($this->platformControlledAiKeys() as $key) {
            unset($data[$key]);
        }

        if (! $gate->aiPlatformEnabled()) {
            unset($data['enabled'], $data['api_key'], $data['model'], $data['base_url'], $data['provider']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mpesaStkAllowedForPayload(array $data): bool
    {
        if (array_key_exists('enable_mpesa_stk', $data)) {
            return (bool) $data['enable_mpesa_stk'];
        }

        return true;
    }

    /**
     * @return array<string, string>|string
     */
    public function normalizeStockDeductOn(
        mixed $value,
        bool $posCheckoutOnCreate = true,
        bool $externalPosEnabled = true,
    ): array|string {
        $allowed = ['order_created', 'order_completed', 'trip_load', 'trip_depart'];
        $defaults = [
            'pos' => 'order_created',
            'mobile' => 'order_completed',
            'backend' => 'order_completed',
        ];

        if (is_string($value) && in_array($value, $allowed, true)) {
            $map = ['pos' => $value, 'mobile' => $value, 'backend' => $value];
        } elseif (is_array($value)) {
            $map = $defaults;
            foreach (['pos', 'mobile', 'backend'] as $channel) {
                $timing = (string) ($value[$channel] ?? '');
                if (in_array($timing, $allowed, true)) {
                    $map[$channel] = $timing;
                }
            }
        } else {
            $map = $defaults;
        }

        if ($externalPosEnabled && $posCheckoutOnCreate) {
            $map['pos'] = 'order_created';
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function kraIntegrationAllowedForPayload(array $data): bool
    {
        if (array_key_exists('enable_kra_integration', $data)) {
            return (bool) $data['enable_kra_integration'];
        }

        return true;
    }
}
