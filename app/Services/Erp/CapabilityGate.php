<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Models\SystemSetting;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Erp\GeneralSettingsResolver;
use App\Services\Erp\ModuleRegistry;
use App\Services\Mpesa\MpesaSettingsResolver;

class CapabilityGate
{
    public function __construct(
        protected ?Organization $organization = null,
    ) {}

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function forOrganization(Organization $organization): self
    {
        return new self($organization);
    }

    public function enabled(string $moduleKey): bool
    {
        if (! $this->organization) {
            return false;
        }

        if (str_ends_with($moduleKey, '.reports')) {
            return $this->reportModuleEnabled($moduleKey);
        }

        if (! $this->selfEnabled($moduleKey)) {
            return false;
        }

        $parent = ModuleRegistry::parentKey($moduleKey);
        if ($parent !== null && $parent !== $moduleKey) {
            return $this->enabled($parent);
        }

        return true;
    }

    /**
     * Report bundles inherit from their parent domain unless the org explicitly
     * disabled the reports submodule in enabled_modules.
     */
    public function reportModuleEnabled(string $moduleKey): bool
    {
        if (! $this->organization) {
            return false;
        }

        if ($this->selfEnabled($moduleKey)) {
            return $this->parentDomainEnabled($moduleKey);
        }

        if (! str_ends_with($moduleKey, '.reports')) {
            return false;
        }

        $parent = ModuleRegistry::parentKey($moduleKey);
        if ($parent === null || ! $this->selfEnabled($parent)) {
            return false;
        }

        $overrides = $this->organizationModuleOverrides();
        if (array_key_exists($moduleKey, $overrides)) {
            return (bool) $overrides[$moduleKey];
        }

        return true;
    }

    protected function parentDomainEnabled(string $moduleKey): bool
    {
        $parent = ModuleRegistry::parentKey($moduleKey);
        if ($parent === null || $parent === $moduleKey) {
            return true;
        }

        return $this->selfEnabled($parent);
    }

    /** @return array<string, bool> */
    protected function organizationModuleOverrides(): array
    {
        if (! $this->organization) {
            return [];
        }

        $overrides = ModuleRegistry::expandLegacyModules(
            is_array($this->organization->enabled_modules) ? $this->organization->enabled_modules : [],
        );

        return $this->compactDenseModuleOverrides($overrides);
    }

    /**
     * Legacy org records stored every module key as false/true. Drop inherited false
     * flags so report bundles follow their parent domain unless a sparse map explicitly
     * disabled them.
     *
     * @param  array<string, bool>  $overrides
     * @return array<string, bool>
     */
    protected function compactDenseModuleOverrides(array $overrides): array
    {
        if ($overrides === []) {
            return [];
        }

        $isSparseMap = count($overrides) < (int) (count(ModuleRegistry::keys()) / 2);
        if ($isSparseMap) {
            return $overrides;
        }

        $compact = [];
        foreach ($overrides as $key => $value) {
            if ($value) {
                $compact[$key] = true;
            }
        }

        return $compact;
    }

    public function selfEnabled(string $moduleKey): bool
    {
        if (! $this->organization) {
            return false;
        }

        return (bool) ($this->resolvedModuleMap()[$moduleKey] ?? false);
    }

    /** @return array<string, bool> */
    protected function resolvedModuleMap(): array
    {
        if (! $this->organization) {
            return [];
        }

        $profile = $this->organization->deployment_profile ?? 'wholesale_retail';
        $profileModules = config("erp.profiles.{$profile}.modules", []);
        $overrides = $this->organizationModuleOverrides();

        $merged = array_merge($profileModules, $overrides);

        $cascaded = ModuleRegistry::cascade(ModuleRegistry::sanitizeModuleMap($merged));

        foreach (ModuleRegistry::domainRoots() as $domain) {
            if (! ($cascaded[$domain] ?? false)) {
                continue;
            }

            foreach (ModuleRegistry::descendantKeys($domain) as $child) {
                if (! str_ends_with($child, '.reports') && ! str_ends_with($child, '.dashboard')) {
                    continue;
                }

                if (array_key_exists($child, $overrides)) {
                    continue;
                }

                $cascaded[$child] = true;
            }
        }

        if (! $this->isPlatformShellOrganization() && $this->tradingTenantHasOperationalModule($cascaded)) {
            $cascaded['admin'] = true;
        }

        return $cascaded;
    }

    protected function isPlatformShellOrganization(): bool
    {
        if (! $this->organization) {
            return false;
        }

        if (strtoupper((string) $this->organization->company_code) === 'PLATFORM') {
            return true;
        }

        $settings = is_array($this->organization->module_settings)
            ? $this->organization->module_settings
            : [];

        return (bool) ($settings['platform'] ?? false);
    }

    /** @param  array<string, bool>  $modules */
    protected function tradingTenantHasOperationalModule(array $modules): bool
    {
        foreach (['sales', 'sales.pos', 'sales.backend', 'sales.mobile', 'inventory', 'customers_suppliers', 'accounting', 'payments', 'hr_payroll', 'distribution'] as $key) {
            if ($modules[$key] ?? false) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, bool> */
    public function allModules(): array
    {
        $out = [];
        foreach (ModuleRegistry::keys() as $key) {
            $out[$key] = $this->enabled($key);
        }

        return $out;
    }

    /** @return list<string> */
    public function allowedChannels(): array
    {
        $channels = [];
        if ($this->enabled('sales.pos')) {
            $channels[] = 'pos';
        }
        if ($this->mobileSalesEnabled()) {
            $channels[] = 'mobile';
        }
        if ($this->enabled('sales.backend')) {
            $channels[] = 'backend';
        }

        return $channels;
    }

    /** @return list<string> User login_channel values allowed for this organization. */
    public function allowedLoginChannels(): array
    {
        $channels = [];
        if ($this->enabled('sales.backend')) {
            $channels[] = \App\Services\Auth\UserLoginChannelService::BACKOFFICE;
        }
        if ($this->enabled('sales.pos')) {
            $channels[] = \App\Services\Auth\UserLoginChannelService::POS;
        }
        if ($this->mobileSalesEnabled()) {
            $channels[] = \App\Services\Auth\UserLoginChannelService::MOBILE;
        }

        return $channels !== []
            ? $channels
            : [\App\Services\Auth\UserLoginChannelService::BACKOFFICE];
    }

    public function channelEnabled(string $channel): bool
    {
        return match ($channel) {
            'pos' => $this->enabled('sales.pos'),
            'mobile' => $this->mobileSalesEnabled(),
            'backend' => $this->enabled('sales.backend'),
            default => false,
        };
    }

    /** Mobile app, mobile login channel, and backoffice mobile-order views when enabled for the org. */
    public function mobileSalesEnabled(): bool
    {
        if (! $this->selfEnabled('sales.mobile')) {
            return false;
        }

        $sales = $this->moduleSettings('sales');

        return (bool) ($sales['enable_mobile_orders'] ?? true);
    }

    public function posOrderEditEnabled(): bool
    {
        if (! $this->enabled('sales.pos')) {
            return false;
        }

        $sales = $this->moduleSettings('sales');

        return (bool) ($sales['enable_pos_order_edit'] ?? false);
    }

    public function backofficeOrderEditEnabled(): bool
    {
        if (! $this->enabled('sales')) {
            return false;
        }

        $sales = $this->moduleSettings('sales');

        return (bool) ($sales['enable_backoffice_order_edit'] ?? true);
    }

    public function mpesaStkPlatformEnabled(): bool
    {
        $finance = $this->moduleSettings('finance');

        return (bool) ($finance['enable_mpesa_stk'] ?? true);
    }

    public function kraIntegrationPlatformEnabled(): bool
    {
        $finance = $this->moduleSettings('finance');

        return (bool) ($finance['enable_kra_integration'] ?? true);
    }

    public function aiPlatformEnabled(): bool
    {
        $ai = $this->moduleSettings('ai');

        return (bool) ($ai['enable_ai'] ?? true);
    }

    public function advancedDataImportPlatformEnabled(): bool
    {
        $admin = $this->moduleSettings('admin');

        return (bool) ($admin['enable_advanced_data_import'] ?? false);
    }

    /** @return array<string, bool> */
    public function advancedDataImportPagesEnabled(): array
    {
        $admin = $this->moduleSettings('admin');
        $overrides = is_array($admin['advanced_data_import_pages'] ?? null)
            ? $admin['advanced_data_import_pages']
            : [];

        return AdvancedDataImportPageRegistry::resolveEnabledMap(
            $overrides,
            $this->advancedDataImportPlatformEnabled(),
        );
    }

    public function advancedDataImportPageEnabled(string $page): bool
    {
        return ($this->advancedDataImportPagesEnabled()[$page] ?? false) === true;
    }

    public function moduleSettings(string $section = 'sales'): array
    {
        $defaults = config("erp.module_settings_defaults.{$section}", []);
        $custom = $this->organization?->module_settings[$section] ?? [];
        $merged = array_merge($defaults, is_array($custom) ? $custom : []);

        if ($section === 'finance') {
            $defaultMpesa = is_array($defaults['mpesa'] ?? null) ? $defaults['mpesa'] : [];
            $customMpesa = is_array($custom['mpesa'] ?? null) ? $custom['mpesa'] : [];
            $merged['mpesa'] = array_merge($defaultMpesa, $customMpesa);
        }

        return $merged;
    }

    /** When inventory is reduced: order_created, order_completed (workflow status), trip_load, or trip_depart. */
    public function stockDeductTiming(): string
    {
        $sales = $this->moduleSettings('sales');
        if (array_key_exists('stock_deduct_on', $sales)) {
            $timing = (string) $sales['stock_deduct_on'];
            if (in_array($timing, ['order_created', 'order_completed', 'trip_load', 'trip_depart'], true)) {
                return $timing;
            }
        }

        $dist = is_array($this->organization?->module_settings['distribution'] ?? null)
            ? $this->organization->module_settings['distribution']
            : [];
        $legacy = (string) ($dist['deduct_stock_on'] ?? 'order_completed');

        return in_array($legacy, ['order_created', 'order_completed', 'trip_load', 'trip_depart'], true)
            ? $legacy
            : 'order_completed';
    }

    public function shouldDeferStockToTrip(): bool
    {
        return $this->distributionOpsEnabled()
            && in_array($this->stockDeductTiming(), ['trip_load', 'trip_depart'], true);
    }

    public function shouldDeductStockAtCheckout(
        OrderWorkflowService $workflow,
        string $orderStatus,
        string $channel,
    ): bool {
        if ($this->shouldDeferStockToTrip()) {
            return false;
        }

        return match ($this->stockDeductTiming()) {
            'order_created' => true,
            'order_completed' => $workflow->shouldDeductStockOn($orderStatus, $channel),
            default => false,
        };
    }

    public function shouldDeductStockOnWorkflowTransition(
        OrderWorkflowService $workflow,
        string $toStatus,
    ): bool {
        if ($this->shouldDeferStockToTrip() || $this->stockDeductTiming() !== 'order_completed') {
            return false;
        }

        $deductStatus = (string) ($workflow->config()['deduct_stock_on'] ?? 'completed');

        return $toStatus === $deductStatus;
    }

    public function shouldReserveStockForOrder(OrderWorkflowService $workflow, string $orderStatus): bool
    {
        if ($this->stockDeductTiming() === 'order_created') {
            return false;
        }

        $reserveStatus = (string) ($workflow->config()['reserve_stock_on'] ?? 'unpaid');

        return $orderStatus === $reserveStatus;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $profile = $this->organization?->deployment_profile ?? 'wholesale_retail';
        $profileConfig = config("erp.profiles.{$profile}", []);

        $system = $this->organization
            ? SystemSetting::query()
                ->where('organization_id', $this->organization->id)
                ->orderBy('id')
                ->first()
            : null;

        $moduleSettings = array_merge(
            config('erp.module_settings_defaults', []),
            $this->organization?->module_settings ?? [],
        );
        if ($this->organization) {
            $sales = is_array($moduleSettings['sales'] ?? null) ? $moduleSettings['sales'] : [];
            $sales['order_workflow'] = OrderWorkflowService::forGate($this)->config();
            $moduleSettings['sales'] = $sales;

            $moduleSettings['general'] = GeneralSettingsResolver::forGate($this);

            $finance = is_array($moduleSettings['finance'] ?? null) ? $moduleSettings['finance'] : [];
            if (isset($finance['mpesa']) && is_array($finance['mpesa'])) {
                $finance['mpesa'] = MpesaSettingsResolver::maskForClient($finance['mpesa']);
            }
            if (! empty($finance['kra_pin_number'])) {
                $finance['kra_pin_number'] = '********';
            }
            $moduleSettings['finance'] = $finance;

            $ai = is_array($moduleSettings['ai'] ?? null) ? $moduleSettings['ai'] : [];
            $moduleSettings['ai'] = AiSettingsResolver::maskForClient(
                array_merge(AiSettingsResolver::defaults(), $ai)
            );

            $moduleSettings['distribution'] = $this->distributionSettings();
            $moduleSettings = $this->maskPlatformDisabledModuleSettings($moduleSettings);
        }

        return [
            'organization_id' => $this->organization?->id,
            'deployment_profile' => $profile,
            'profile_label' => $profileConfig['label'] ?? $profile,
            'distribution_ops_enabled' => $this->distributionOpsEnabled(),
            'mobile_orders_enabled' => $this->mobileSalesEnabled(),
            'pos_order_edit_enabled' => $this->posOrderEditEnabled(),
            'backoffice_order_edit_enabled' => $this->backofficeOrderEditEnabled(),
            'platform_mpesa_stk_enabled' => $this->mpesaStkPlatformEnabled(),
            'platform_kra_integration_enabled' => $this->kraIntegrationPlatformEnabled(),
            'platform_ai_enabled' => $this->aiPlatformEnabled(),
            'platform_advanced_data_import_enabled' => $this->advancedDataImportPlatformEnabled(),
            'advanced_data_import_pages' => $this->advancedDataImportPagesEnabled(),
            'modules' => $this->allModules(),
            'channels' => $this->allowedChannels(),
            'allowed_login_channels' => $this->allowedLoginChannels(),
            'workflows' => $this->workflowForOrg(),
            'module_settings' => $moduleSettings,
            'ai_assistant' => $this->organization
                ? AiSettingsResolver::clientCapabilities($this)
                : ['enabled' => false, 'available' => false],
            'allow_negative_stock' => (bool) ($system?->allow_below_stock ?? false),
            'stock_alert_mode' => $system?->stock_alert_mode ?? 'per_product',
            'global_low_stock_threshold' => $system?->global_low_stock_threshold,
            'general' => $this->organization
                ? GeneralSettingsResolver::forGate($this)
                : GeneralSettingsResolver::normalize(GeneralSettingsResolver::defaults()),
            'session_idle_minutes' => $this->organization
                ? \App\Services\Auth\SecuritySettingsResolver::forGate($this)['session_idle_minutes']
                : (int) config('erp.session_idle_minutes', 15),
            'catalog' => $this->organization
                ? app(ProductCatalogScopeService::class)->metadata((int) $this->organization->id)
                : [
                    'multi_branch' => false,
                    'branch_count' => 0,
                    'head_office_branch_id' => null,
                    'head_office_branch_code' => null,
                    'head_office_branch_name' => null,
                    'default_branch_id' => null,
                ],
        ];
    }

    /** @return array<string, mixed> */
    protected function workflowForOrg(): array
    {
        return OrderWorkflowService::forGate($this)->workflowsByChannel();
    }

    /** @param  array<string, mixed>  $moduleSettings */
    public function maskPlatformDisabledModuleSettings(array $moduleSettings): array
    {
        if (! $this->aiPlatformEnabled()) {
            unset($moduleSettings['ai']);
        }

        if (isset($moduleSettings['finance']) && is_array($moduleSettings['finance'])) {
            if (! $this->mpesaStkPlatformEnabled()) {
                unset($moduleSettings['finance']['mpesa'], $moduleSettings['finance']['enable_mpesa_stk']);
            }
            if (! $this->kraIntegrationPlatformEnabled()) {
                foreach ([
                    'enable_kra_device', 'kra_device_ip', 'kra_device_hardware_ip', 'kra_serial_number', 'kra_pin_number',
                    'kra_device_test_mode', 'kra_plu_register_path', 'default_submit_kra', 'kra_bypass_above_amount',
                ] as $key) {
                    unset($moduleSettings['finance'][$key]);
                }
            }
        }

        if (isset($moduleSettings['sales']) && is_array($moduleSettings['sales'])) {
            if (! $this->mobileSalesEnabled()) {
                unset(
                    $moduleSettings['sales']['mobile_enable_checkout_location_verification'],
                    $moduleSettings['sales']['mobile_allow_offline_orders'],
                    $moduleSettings['sales']['mobile_checkout_location_radius_metres'],
                    $moduleSettings['sales']['mobile_enable_field_attendance'],
                );
            }
        }

        return $moduleSettings;
    }

    public function distributionOpsEnabled(): bool
    {
        // Platform enables the distribution module → operational features on by default.
        return $this->enabled('distribution');
    }

    /** @return array<string, mixed> */
    public function distributionSettings(): array
    {
        $defaults = config('erp.module_settings_defaults.distribution', []);
        $custom = $this->organization?->module_settings['distribution'] ?? [];
        $merged = array_merge($defaults, is_array($custom) ? $custom : []);

        $legacyKeys = [
            'enable_distribution_ops',
            'inherit_customer_route',
            'assign_on_status',
            'auto_assign_truck',
            'auto_assign_driver',
            'require_weight_on_load',
            'set_delivery_date_on',
            'require_pod_on_delivered',
        ];
        $sales = is_array($this->organization?->module_settings['sales'] ?? null)
            ? $this->organization->module_settings['sales']
            : [];

        foreach ($legacyKeys as $key) {
            if (! array_key_exists($key, $custom) && array_key_exists($key, $sales)) {
                $merged[$key] = $sales[$key];
            }
        }

        $merged['enable_distribution_ops'] = $this->distributionOpsEnabled();

        return $merged;
    }
}
