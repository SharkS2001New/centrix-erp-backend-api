<?php

namespace App\Services;

use App\Models\Organization;
use App\Services\Erp\AdvancedDataImportPageRegistry;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\ClassicPosThemeSettings;
use App\Services\Sales\PosCashRoundingSettings;
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
    public function platformControlledWhatsappKeys(): array
    {
        return config('erp.platform_controlled.whatsapp', []);
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

    /** @return list<string> */
    public function platformControlledGeneralKeys(): array
    {
        return config('erp.platform_controlled.general', []);
    }

    /** @return list<string> */
    public function platformControlledHrPayrollKeys(): array
    {
        return config('erp.platform_controlled.hr_payroll', []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerGeneralPayload(array $data): array
    {
        foreach ($this->platformControlledGeneralKeys() as $key) {
            unset($data[$key]);
        }

        return $data;
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

        if (array_key_exists('enable_held_order_amount_paid', $salesPlatform)) {
            $nextSales['enable_held_order_amount_paid'] = (bool) $salesPlatform['enable_held_order_amount_paid'];
        }

        if (array_key_exists('pos_combine_identical_lines', $salesPlatform)) {
            $nextSales['pos_combine_identical_lines'] = (bool) $salesPlatform['pos_combine_identical_lines'];
        }

        if (array_key_exists('append_same_day_customer_orders', $salesPlatform)) {
            $nextSales['append_same_day_customer_orders'] = (bool) $salesPlatform['append_same_day_customer_orders'];
        }

        if (array_key_exists('enable_pos_cash_rounding', $salesPlatform)) {
            $nextSales['enable_pos_cash_rounding'] = (bool) $salesPlatform['enable_pos_cash_rounding'];
        }

        if (array_key_exists('receipt_show_all_payment_methods', $salesPlatform)) {
            $nextSales['receipt_show_all_payment_methods'] = (bool) $salesPlatform['receipt_show_all_payment_methods'];
        }

        if (array_key_exists('external_pos_layout', $salesPlatform)) {
            $nextSales['external_pos_layout'] = $this->normalizePosUiLayout(
                $salesPlatform['external_pos_layout'],
            );
        }

        if (array_key_exists('backoffice_order_edit_layout', $salesPlatform)) {
            $nextSales['backoffice_order_edit_layout'] = $this->normalizePosUiLayout(
                $salesPlatform['backoffice_order_edit_layout'],
            );
        }

        if (array_key_exists('classic_pos_theme_template', $salesPlatform)) {
            $nextSales['classic_pos_theme_template'] = ClassicPosThemeSettings::normalizeThemeTemplate(
                $salesPlatform['classic_pos_theme_template'],
            );
        }

        if (array_key_exists('classic_pos_theme_colors', $salesPlatform)) {
            $nextSales['classic_pos_theme_colors'] = ClassicPosThemeSettings::normalizeThemeColors(
                $salesPlatform['classic_pos_theme_colors'],
            );
        }

        if (array_key_exists('erp_theme_template', $salesPlatform)) {
            $nextSales['erp_theme_template'] = ClassicPosThemeSettings::normalizeThemeTemplate(
                $salesPlatform['erp_theme_template'],
            );
        }

        if (array_key_exists('erp_theme_colors', $salesPlatform)) {
            $nextSales['erp_theme_colors'] = ClassicPosThemeSettings::normalizeThemeColors(
                $salesPlatform['erp_theme_colors'],
            );
        }

        if (array_key_exists('external_pos_theme_template', $salesPlatform)) {
            $nextSales['external_pos_theme_template'] = ClassicPosThemeSettings::normalizeThemeTemplate(
                $salesPlatform['external_pos_theme_template'],
            );
        }

        if (array_key_exists('external_pos_theme_colors', $salesPlatform)) {
            $nextSales['external_pos_theme_colors'] = ClassicPosThemeSettings::normalizeThemeColors(
                $salesPlatform['external_pos_theme_colors'],
            );
        }

        if (array_key_exists('enable_backoffice_order_edit', $salesPlatform)) {
            $nextSales['enable_backoffice_order_edit'] = (bool) $salesPlatform['enable_backoffice_order_edit'];
        }

        if (array_key_exists('edit_order_statuses', $salesPlatform)) {
            $nextSales['edit_order_statuses'] = $this->normalizeRequiredActionStatuses(
                $salesPlatform['edit_order_statuses'],
                config('erp.module_settings_defaults.sales.edit_order_statuses', ['booked', 'pending', 'editable']),
            );
        }

        if (array_key_exists('print_invoice_statuses', $salesPlatform)) {
            $nextSales['print_invoice_statuses'] = $this->normalizeOptionalActionStatuses(
                $salesPlatform['print_invoice_statuses'],
            );
        }

        if (array_key_exists('collect_payment_statuses', $salesPlatform)) {
            $nextSales['collect_payment_statuses'] = $this->normalizeRequiredActionStatuses(
                $salesPlatform['collect_payment_statuses'],
                config('erp.module_settings_defaults.sales.collect_payment_statuses', ['unpaid', 'pending_payment']),
            );
        }

        if (array_key_exists('cancel_order_statuses', $salesPlatform)) {
            $cancelFallback = OrderWorkflowService::defaultCancelOrderStatusesFromWorkflowConfig(
                is_array($nextSales['order_workflow'] ?? null)
                    ? $nextSales['order_workflow']
                    : $workflowService->config(),
            );
            $nextSales['cancel_order_statuses'] = $this->normalizeRequiredActionStatuses(
                $salesPlatform['cancel_order_statuses'],
                $cancelFallback,
            );
        }

        if (array_key_exists('convert_to_paid_statuses', $salesPlatform)) {
            $nextSales['convert_to_paid_statuses'] = $this->normalizeActionStatusList(
                $salesPlatform['convert_to_paid_statuses'],
            );
        }

        if (array_key_exists('convert_to_unpaid_statuses', $salesPlatform)) {
            $nextSales['convert_to_unpaid_statuses'] = $this->normalizeActionStatusList(
                $salesPlatform['convert_to_unpaid_statuses'],
            );
        }

        if (array_key_exists('customer_return_statuses', $salesPlatform)) {
            $nextSales['customer_return_statuses'] = $this->normalizeRequiredActionStatuses(
                $salesPlatform['customer_return_statuses'],
                config('erp.module_settings_defaults.sales.customer_return_statuses', ['paid', 'processed', 'delivered', 'completed']),
            );
        }

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['sales'] = $nextSales;

        if (array_key_exists('hotel_pos_grid_columns', $salesPlatform)) {
            $currentHospitality = is_array($moduleSettings['hospitality'] ?? null)
                ? $moduleSettings['hospitality']
                : [];
            $currentHospitality['hotel_pos_grid_columns'] = \App\Services\Hospitality\HospitalityPosSettings::normalizeGridColumns(
                $salesPlatform['hotel_pos_grid_columns'],
            );
            if (array_key_exists('hotel_pos_collect_payment', $salesPlatform)) {
                $currentHospitality['hotel_pos_collect_payment'] = \App\Services\Hospitality\HospitalityPosSettings::normalizeCollectPayment(
                    $salesPlatform['hotel_pos_collect_payment'],
                );
            }
            if (array_key_exists('hotel_pos_catalog_limit', $salesPlatform)) {
                $currentHospitality['hotel_pos_catalog_limit'] = \App\Services\Hospitality\HospitalityPosSettings::normalizeCatalogLimit(
                    $salesPlatform['hotel_pos_catalog_limit'],
                );
            }
            if (array_key_exists('hotel_pos_theme_template', $salesPlatform)) {
                $currentHospitality['hotel_pos_theme_template'] = \App\Services\Hospitality\HospitalityPosSettings::normalizeThemeTemplate(
                    $salesPlatform['hotel_pos_theme_template'],
                );
            }
            if (array_key_exists('hospitality_services', $salesPlatform) && is_array($salesPlatform['hospitality_services'])) {
                $currentHospitality['services'] = \App\Services\Hospitality\HospitalityServices::normalize(
                    $salesPlatform['hospitality_services'],
                );
            }
            if (array_key_exists('hospitality_payment_workflow', $salesPlatform) && is_array($salesPlatform['hospitality_payment_workflow'])) {
                $currentHospitality['payment_workflow'] = \App\Services\Hospitality\HospitalityPaymentWorkflow::normalize(
                    $salesPlatform['hospitality_payment_workflow'],
                );
            }
            // Checkout mode is XOR: pay-now OR save-for-later (never both).
            if (array_key_exists('hotel_pos_collect_payment', $salesPlatform)) {
                $collectNow = \App\Services\Hospitality\HospitalityPosSettings::normalizeCollectPayment(
                    $salesPlatform['hotel_pos_collect_payment'],
                );
                $workflow = is_array($currentHospitality['payment_workflow'] ?? null)
                    ? $currentHospitality['payment_workflow']
                    : \App\Services\Hospitality\HospitalityPaymentWorkflow::DEFAULTS;
                if ($collectNow) {
                    $workflow['unpaid'] = false;
                    $workflow['paid'] = true;
                } else {
                    $workflow['unpaid'] = true;
                    $workflow['paid'] = true;
                }
                $currentHospitality['payment_workflow'] = \App\Services\Hospitality\HospitalityPaymentWorkflow::normalize($workflow);
            }
            $moduleSettings['hospitality'] = $currentHospitality;
        } elseif (
            array_key_exists('hotel_pos_collect_payment', $salesPlatform)
            || array_key_exists('hotel_pos_catalog_limit', $salesPlatform)
            || array_key_exists('hotel_pos_theme_template', $salesPlatform)
            || array_key_exists('hospitality_services', $salesPlatform)
            || array_key_exists('hospitality_payment_workflow', $salesPlatform)
        ) {
            $currentHospitality = is_array($moduleSettings['hospitality'] ?? null)
                ? $moduleSettings['hospitality']
                : [];
            if (array_key_exists('hotel_pos_collect_payment', $salesPlatform)) {
                $currentHospitality['hotel_pos_collect_payment'] = \App\Services\Hospitality\HospitalityPosSettings::normalizeCollectPayment(
                    $salesPlatform['hotel_pos_collect_payment'],
                );
            }
            if (array_key_exists('hotel_pos_catalog_limit', $salesPlatform)) {
                $currentHospitality['hotel_pos_catalog_limit'] = \App\Services\Hospitality\HospitalityPosSettings::normalizeCatalogLimit(
                    $salesPlatform['hotel_pos_catalog_limit'],
                );
            }
            if (array_key_exists('hotel_pos_theme_template', $salesPlatform)) {
                $currentHospitality['hotel_pos_theme_template'] = \App\Services\Hospitality\HospitalityPosSettings::normalizeThemeTemplate(
                    $salesPlatform['hotel_pos_theme_template'],
                );
            }
            if (array_key_exists('hospitality_services', $salesPlatform) && is_array($salesPlatform['hospitality_services'])) {
                $currentHospitality['services'] = \App\Services\Hospitality\HospitalityServices::normalize(
                    $salesPlatform['hospitality_services'],
                );
            }
            if (array_key_exists('hospitality_payment_workflow', $salesPlatform) && is_array($salesPlatform['hospitality_payment_workflow'])) {
                $currentHospitality['payment_workflow'] = \App\Services\Hospitality\HospitalityPaymentWorkflow::normalize(
                    $salesPlatform['hospitality_payment_workflow'],
                );
            }
            if (array_key_exists('hotel_pos_collect_payment', $salesPlatform)) {
                $collectNow = \App\Services\Hospitality\HospitalityPosSettings::normalizeCollectPayment(
                    $salesPlatform['hotel_pos_collect_payment'],
                );
                $workflow = is_array($currentHospitality['payment_workflow'] ?? null)
                    ? $currentHospitality['payment_workflow']
                    : \App\Services\Hospitality\HospitalityPaymentWorkflow::DEFAULTS;
                if ($collectNow) {
                    $workflow['unpaid'] = false;
                    $workflow['paid'] = true;
                } else {
                    $workflow['unpaid'] = true;
                    $workflow['paid'] = true;
                }
                $currentHospitality['payment_workflow'] = \App\Services\Hospitality\HospitalityPaymentWorkflow::normalize($workflow);
            }
            $moduleSettings['hospitality'] = $currentHospitality;
        }

        $currentDistribution = is_array($moduleSettings['distribution'] ?? null) ? $moduleSettings['distribution'] : [];
        foreach ($this->platformControlledDistributionKeys() as $key) {
            if (array_key_exists($key, $salesPlatform)) {
                $currentDistribution[$key] = (bool) $salesPlatform[$key];
            }
        }
        $moduleSettings['distribution'] = $currentDistribution;

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

        $currentWhatsapp = is_array($moduleSettings['whatsapp'] ?? null) ? $moduleSettings['whatsapp'] : [];
        foreach ($this->platformControlledWhatsappKeys() as $key) {
            if (array_key_exists($key, $salesPlatform)) {
                $currentWhatsapp[$key] = (bool) $salesPlatform[$key];
            }
        }
        if (array_key_exists('enable_whatsapp_orders', $salesPlatform) && ! $salesPlatform['enable_whatsapp_orders']) {
            $currentWhatsapp['enabled'] = false;
        }
        $moduleSettings['whatsapp'] = $currentWhatsapp;

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

        if (array_key_exists('orders_list_default_days', $nextSales)) {
            $nextSales['orders_list_default_days'] = $this->normalizeOrdersListDefaultDays($nextSales['orders_list_default_days']);
        }
        if (array_key_exists('reports_default_date_range_days', $nextSales)) {
            $nextSales['reports_default_date_range_days'] = $this->normalizeReportsDefaultDateRangeDays(
                $nextSales['reports_default_date_range_days'],
            );
        }
        if (array_key_exists('orders_list_search_days', $nextSales)) {
            $nextSales['orders_list_search_days'] = $this->normalizeOrdersListSearchDays(
                $nextSales['orders_list_search_days'],
                (int) ($nextSales['orders_list_default_days'] ?? 14),
            );
        }
        if (array_key_exists('orders_list_sort', $nextSales)) {
            $nextSales['orders_list_sort'] = $this->normalizeOrdersListSort($nextSales['orders_list_sort']);
        }
        if (array_key_exists('orders_list_visible_columns', $nextSales)) {
            $nextSales['orders_list_visible_columns'] = $this->normalizeOrdersListVisibleColumns(
                $nextSales['orders_list_visible_columns'],
            );
        }
        if (array_key_exists('orders_list_visible_columns_by_queue', $nextSales)) {
            $nextSales['orders_list_visible_columns_by_queue'] = $this->normalizeOrdersListVisibleColumnsByQueue(
                $nextSales['orders_list_visible_columns_by_queue'],
            );
        }

        $org->forceFill(['module_settings' => $moduleSettings])->save();

        app(\App\Services\Erp\ErpContext::class)->forgetOrganizationCache((int) $org->id);

        return $org->fresh();
    }

    /**
     * @param  array<string, mixed>  $payrollPlatform
     */
    public function applyPayrollPlatformConfig(Organization $org, array $payrollPlatform): Organization
    {
        if ($payrollPlatform === []) {
            return $org;
        }

        $moduleSettings = $org->module_settings ?? [];
        $currentHr = is_array($moduleSettings['hr_payroll'] ?? null) ? $moduleSettings['hr_payroll'] : [];

        foreach ($this->platformControlledHrPayrollKeys() as $key) {
            if (! array_key_exists($key, $payrollPlatform)) {
                continue;
            }
            $value = $payrollPlatform[$key];
            if ($key === 'shif_minimum_monthly' && ($value === '' || $value === false)) {
                $value = null;
            }
            $currentHr[$key] = $value;
        }

        $moduleSettings['hr_payroll'] = \App\Services\Hr\HrPayrollSettingsResolver::normalize($currentHr);
        $org->forceFill(['module_settings' => $moduleSettings])->save();

        app(\App\Services\Erp\ErpContext::class)->forgetOrganizationCache((int) $org->id);

        return $org->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function payrollPlatformConfigForOrganization(Organization $org): array
    {
        $hr = \App\Services\Hr\HrPayrollSettingsResolver::forOrganization($org);

        return [
            'payroll_month_days_basis' => $hr['payroll_month_days_basis'] ?? 'calendar',
            'shif_minimum_monthly' => $hr['shif_minimum_monthly'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterOrgManagerHrPayrollPayload(array $data): array
    {
        foreach ($this->platformControlledHrPayrollKeys() as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Default platform sales config for a new tenant.
     *
     * @return array<string, mixed>
     */
    public function defaultSalesPlatformConfig(string $deploymentProfile = 'wholesale_retail'): array
    {
        $isDistribution = $deploymentProfile === 'distribution';

        $workflowDefaults = config('erp.default_order_workflow', []);

        return [
            'show_checkout_on_create_order' => true,
            'enable_mobile_orders' => ! in_array($deploymentProfile, ['small_shop', 'supermarket'], true),
            'mobile_enable_field_attendance' => false,
            'mobile_enable_driver_app' => in_array($deploymentProfile, ['distribution', 'wholesale_retail'], true),
            'mobile_enable_driver_attendance' => false,
            'enable_mpesa_stk' => true,
            'enable_kra_integration' => true,
            'enable_ai' => true,
            'enable_whatsapp_orders' => false,
            'enable_advanced_data_import' => false,
            'advanced_data_import_pages' => AdvancedDataImportPageRegistry::defaultEnabledMap(),
            'stock_deduct_on' => [
                'pos' => 'order_created',
                'mobile' => 'order_completed',
                'backend' => 'order_completed',
            ],
            'require_pos_till_float' => false,
            'enable_pos_cash_rounding' => false,
            'receipt_show_all_payment_methods' => true,
            'external_pos_layout' => 'modern',
            'classic_pos_theme_template' => ClassicPosThemeSettings::THEME_TEMPLATE_DEFAULT,
            'classic_pos_theme_colors' => [],
            'hotel_pos_grid_columns' => 4,
            'hotel_pos_collect_payment' => true,
            'hotel_pos_catalog_limit' => 30,
            'hotel_pos_theme_template' => 'centrix',
            'hospitality_services' => \App\Services\Hospitality\HospitalityServices::DEFAULTS,
            'hospitality_payment_workflow' => \App\Services\Hospitality\HospitalityPaymentWorkflow::DEFAULTS,
            'order_workflow' => $workflowDefaults,
            'order_cancellation_enabled' => true,
            'enable_pos_order_edit' => false,
            'enable_held_order_amount_paid' => false,
            'pos_combine_identical_lines' => true,
            'append_same_day_customer_orders' => false,
            'enable_backoffice_order_edit' => true,
            'backoffice_order_edit_layout' => 'modern',
            'reserve_stock_on_cart' => true,
            'cart_reservation_ttl_minutes' => 15,
            // Wholesale/retail: 2 weeks list / 1 month search. Distribution: wider operational window.
            'orders_list_default_days' => $isDistribution ? 30 : 14,
            'reports_default_date_range_days' => 30,
            'orders_list_search_days' => $isDistribution ? 60 : 30,
            'orders_list_sort' => '-created_at',
            'orders_list_visible_columns' => $this->normalizeOrdersListVisibleColumns(
                config('erp.module_settings_defaults.sales.orders_list_visible_columns', []),
            ),
            'orders_list_visible_columns_by_queue' => $this->normalizeOrdersListVisibleColumnsByQueue(
                config('erp.module_settings_defaults.sales.orders_list_visible_columns_by_queue', []),
            ),
            'edit_order_statuses' => ['booked', 'pending', 'editable'],
            'print_invoice_statuses' => null,
            'collect_payment_statuses' => ['unpaid', 'pending_payment'],
            'cancel_order_statuses' => OrderWorkflowService::defaultCancelOrderStatusesFromWorkflowConfig($workflowDefaults),
            'convert_to_paid_statuses' => [],
            'convert_to_unpaid_statuses' => [],
            'customer_return_statuses' => ['paid', 'processed', 'delivered', 'completed'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salesPlatformConfigForOrganization(Organization $org): array
    {
        $gate = app(CapabilityGate::class)->forOrganization($org);
        $sales = $gate->moduleSettings('sales');
        $customSales = is_array($org->module_settings['sales'] ?? null)
            ? $org->module_settings['sales']
            : [];
        $distribution = $gate->distributionSettings();
        $inventory = $gate->moduleSettings('inventory');
        $finance = $gate->moduleSettings('finance');
        $ai = $gate->moduleSettings('ai');
        $whatsapp = $gate->moduleSettings('whatsapp');
        $admin = $gate->moduleSettings('admin');
        $workflow = OrderWorkflowService::forGate($gate)->config();
        $importPages = AdvancedDataImportPageRegistry::resolveEnabledMap(
            is_array($admin['advanced_data_import_pages'] ?? null) ? $admin['advanced_data_import_pages'] : [],
            (bool) ($admin['enable_advanced_data_import'] ?? false),
        );

        return [
            'show_checkout_on_create_order' => (bool) ($sales['show_checkout_on_create_order'] ?? true),
            'enable_mobile_orders' => (bool) ($sales['enable_mobile_orders'] ?? true),
            'enable_mobile_orders_returns_card' => (bool) ($sales['enable_mobile_orders_returns_card'] ?? false),
            'enable_mobile_orders_payments_card' => (bool) ($sales['enable_mobile_orders_payments_card'] ?? false),
            'mobile_enable_field_attendance' => (bool) ($sales['mobile_enable_field_attendance'] ?? false),
            'mobile_enable_driver_app' => (bool) ($distribution['mobile_enable_driver_app'] ?? true),
            'mobile_enable_driver_attendance' => (bool) ($distribution['mobile_enable_driver_attendance'] ?? false),
            'enable_mpesa_stk' => (bool) ($finance['enable_mpesa_stk'] ?? true),
            'enable_kra_integration' => (bool) ($finance['enable_kra_integration'] ?? true),
            'enable_ai' => (bool) ($ai['enable_ai'] ?? true),
            'enable_whatsapp_orders' => (bool) ($whatsapp['enable_whatsapp_orders'] ?? false),
            'enable_advanced_data_import' => (bool) ($admin['enable_advanced_data_import'] ?? false),
            'advanced_data_import_pages' => $importPages,
            'stock_deduct_on' => $this->normalizeStockDeductOn(
                $sales['stock_deduct_on'] ?? null,
                (bool) ($sales['show_checkout_on_create_order'] ?? true),
                (bool) ($org->enabled_modules['sales.pos'] ?? false),
            ),
            'require_pos_till_float' => (bool) ($sales['require_pos_till_float'] ?? false),
            'enable_pos_cash_rounding' => PosCashRoundingSettings::enabled($sales, $customSales),
            'receipt_show_all_payment_methods' => (bool) ($sales['receipt_show_all_payment_methods'] ?? true),
            'external_pos_layout' => in_array(($sales['external_pos_layout'] ?? 'modern'), ['modern', 'classic'], true)
                ? (string) $sales['external_pos_layout']
                : 'modern',
            'backoffice_order_edit_layout' => $this->normalizePosUiLayout(
                $sales['backoffice_order_edit_layout'] ?? 'modern',
            ),
            'classic_pos_theme_template' => ClassicPosThemeSettings::normalizeThemeTemplate(
                $sales['classic_pos_theme_template'] ?? ClassicPosThemeSettings::THEME_TEMPLATE_DEFAULT,
            ),
            'classic_pos_theme_colors' => ClassicPosThemeSettings::normalizeThemeColors(
                $sales['classic_pos_theme_colors'] ?? [],
            ),
            'erp_theme_template' => ClassicPosThemeSettings::resolveErpThemeTemplate($sales),
            'erp_theme_colors' => ClassicPosThemeSettings::resolveErpThemeColors($sales),
            'external_pos_theme_template' => ClassicPosThemeSettings::resolveExternalPosThemeTemplate($sales),
            'external_pos_theme_colors' => ClassicPosThemeSettings::resolveExternalPosThemeColors($sales),
            'hotel_pos_grid_columns' => \App\Services\Hospitality\HospitalityPosSettings::gridColumnsForOrganization($org),
            'hotel_pos_collect_payment' => \App\Services\Hospitality\HospitalityPosSettings::forOrganization($org)['hotel_pos_collect_payment'],
            'hotel_pos_catalog_limit' => \App\Services\Hospitality\HospitalityPosSettings::forOrganization($org)['hotel_pos_catalog_limit'],
            'hotel_pos_theme_template' => \App\Services\Hospitality\HospitalityPosSettings::forOrganization($org)['hotel_pos_theme_template'],
            'hospitality_services' => \App\Services\Hospitality\HospitalityServices::forOrganization($org),
            'hospitality_payment_workflow' => \App\Services\Hospitality\HospitalityPaymentWorkflow::forOrganization($org),
            'enable_mpesa_code' => (bool) ($sales['enable_mpesa_code'] ?? false),
            'enable_cheque_number' => (bool) ($sales['enable_cheque_number'] ?? false),
            'enable_pos_order_edit' => (bool) ($sales['enable_pos_order_edit'] ?? false),
            'enable_held_order_amount_paid' => (bool) ($sales['enable_held_order_amount_paid'] ?? false),
            'pos_combine_identical_lines' => ($sales['pos_combine_identical_lines'] ?? true) !== false,
            'append_same_day_customer_orders' => (bool) ($sales['append_same_day_customer_orders'] ?? false),
            'enable_backoffice_order_edit' => (bool) ($sales['enable_backoffice_order_edit'] ?? true),
            'edit_order_statuses' => $this->normalizeRequiredActionStatuses(
                $sales['edit_order_statuses'] ?? null,
                config('erp.module_settings_defaults.sales.edit_order_statuses', ['booked', 'pending', 'editable']),
            ),
            'print_invoice_statuses' => $this->normalizeOptionalActionStatuses(
                $sales['print_invoice_statuses'] ?? null,
            ),
            'collect_payment_statuses' => $this->normalizeRequiredActionStatuses(
                $sales['collect_payment_statuses'] ?? null,
                config('erp.module_settings_defaults.sales.collect_payment_statuses', ['unpaid', 'pending_payment']),
            ),
            'cancel_order_statuses' => $this->normalizeRequiredActionStatuses(
                $sales['cancel_order_statuses'] ?? null,
                OrderWorkflowService::defaultCancelOrderStatusesFromWorkflowConfig($workflow),
            ),
            'convert_to_paid_statuses' => $this->normalizeActionStatusList(
                $sales['convert_to_paid_statuses'] ?? [],
            ),
            'convert_to_unpaid_statuses' => $this->normalizeActionStatusList(
                $sales['convert_to_unpaid_statuses'] ?? [],
            ),
            'customer_return_statuses' => $this->normalizeRequiredActionStatuses(
                $sales['customer_return_statuses'] ?? null,
                config('erp.module_settings_defaults.sales.customer_return_statuses', ['paid', 'processed', 'delivered', 'completed']),
            ),
            'order_workflow' => $workflow,
            'reserve_stock_on_cart' => ($inventory['reserve_stock_on_cart'] ?? true) !== false,
            'cart_reservation_ttl_minutes' => min(
                15,
                max(0, (int) ($inventory['cart_reservation_ttl_minutes'] ?? 15)),
            ),
            'order_expiry_enabled' => ($sales['order_expiry_enabled'] ?? true) !== false,
            'order_expiry_days' => max(1, min(90, (int) ($sales['order_expiry_days'] ?? 5))),
            'order_expiry_before_status' => (string) ($sales['order_expiry_before_status'] ?? 'processed'),
            'order_cancellation_enabled' => ($sales['order_cancellation_enabled'] ?? true) !== false,
            'orders_list_default_days' => $this->normalizeOrdersListDefaultDays($sales['orders_list_default_days'] ?? null),
            'reports_default_date_range_days' => $this->normalizeReportsDefaultDateRangeDays(
                $sales['reports_default_date_range_days'] ?? null,
            ),
            'orders_list_search_days' => $this->normalizeOrdersListSearchDays(
                $sales['orders_list_search_days'] ?? null,
                $this->normalizeOrdersListDefaultDays($sales['orders_list_default_days'] ?? null),
            ),
            'orders_list_sort' => $this->normalizeOrdersListSort($sales['orders_list_sort'] ?? null),
            'orders_list_visible_columns' => $this->normalizeOrdersListVisibleColumns(
                $sales['orders_list_visible_columns'] ?? null,
            ),
            'orders_list_visible_columns_by_queue' => $this->normalizeOrdersListVisibleColumnsByQueue(
                $sales['orders_list_visible_columns_by_queue'] ?? null,
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
        unset(
            $data['edit_order_statuses'],
            $data['print_invoice_statuses'],
            $data['collect_payment_statuses'],
            $data['cancel_order_statuses'],
            $data['customer_return_statuses'],
        );

        if ($gate && ! $gate->mobileSalesEnabled()) {
            foreach (array_keys($data) as $key) {
                if (
                    str_starts_with($key, 'mobile_')
                    || $key === 'enable_mobile_orders'
                    || str_starts_with($key, 'enable_mobile_orders_')
                ) {
                    unset($data[$key]);
                }
            }
        }

        if ($gate && ! $gate->driverMobileEnabled()) {
            unset($data['mobile_enable_driver_app'], $data['mobile_enable_driver_attendance']);
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
     * @return array<string, mixed>
     */
    public function filterOrgManagerWhatsappPayload(array $data, CapabilityGate $gate): array
    {
        foreach ($this->platformControlledWhatsappKeys() as $key) {
            unset($data[$key]);
        }

        if (! $gate->whatsappPlatformEnabled()) {
            unset(
                $data['enabled'],
                $data['agent_name'],
                $data['display_phone'],
                $data['phone_number_id'],
                $data['waba_id'],
                $data['access_token'],
                $data['bot_user_id'],
                $data['branch_id'],
                $data['graph_api_version'],
            );
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
        $allowed = ['order_created', 'order_completed', 'trip_pick', 'trip_load', 'trip_depart'];
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

    public function normalizeOrdersListDefaultDays(mixed $value): int
    {
        $days = (int) $value;

        if ($days < 1) {
            return 14;
        }

        return min(90, $days);
    }

    public function normalizeReportsDefaultDateRangeDays(mixed $value): int
    {
        $days = (int) $value;

        if ($days < 1) {
            return 30;
        }

        return min(90, $days);
    }

    /**
     * Search window (days). Never narrower than the default list filter window.
     */
    public function normalizeOrdersListSearchDays(mixed $value, ?int $defaultListDays = null): int
    {
        $minDays = max(1, (int) ($defaultListDays ?? 14));
        $days = (int) $value;

        if ($days < 1) {
            $days = max(30, $minDays);
        }

        return min(90, max($minDays, $days));
    }

    public function normalizeOrdersListSort(mixed $value): string
    {
        $allowed = ['-created_at', 'created_at', '-order_num', 'order_num'];
        $sort = (string) ($value ?? '-created_at');

        return in_array($sort, $allowed, true) ? $sort : '-created_at';
    }

    /**
     * @return list<string>
     */
    public function normalizeOrdersListVisibleColumns(mixed $value): array
    {
        $allowed = [
            'order',
            'customer',
            'branch',
            'route',
            'delivery_date',
            'connectivity',
            'amount',
            'amount_paid',
            'balance',
            'discount',
            'vat',
            'status',
            'method',
            'source',
            'placed_by',
        ];
        $incoming = is_array($value)
            ? $value
            : config('erp.module_settings_defaults.sales.orders_list_visible_columns', $allowed);

        $seen = [];
        $out = [];
        foreach ($incoming as $item) {
            $key = trim((string) $item);
            if ($key === '' || isset($seen[$key]) || ! in_array($key, $allowed, true)) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $key;
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    public function normalizeOrdersListVisibleColumnsByQueue(mixed $value): array
    {
        $allowedQueues = [
            'all',
            'booked',
            'pending',
            'unpaid',
            'pending_payment',
            'paid',
            'processed',
            'delivered',
            'completed',
            'mobile',
            'whatsapp',
            'pending_approval',
            'editable',
            'cancelled',
            'expired',
        ];
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $queue => $columns) {
            $key = trim((string) $queue);
            if ($key === '' || ! in_array($key, $allowedQueues, true)) {
                continue;
            }
            $out[$key] = $this->normalizeOrdersListVisibleColumns($columns);
        }

        return $out;
    }

    /**
     * Required action stage list (edit / collect). Empty input falls back to defaults.
     *
     * @param  list<string>|null  $fallback
     * @return list<string>
     */
    public function normalizeRequiredActionStatuses(mixed $value, array $fallback): array
    {
        $normalized = $this->normalizeActionStatusList($value);
        if ($normalized === []) {
            return array_values(array_unique(array_map('strval', $fallback)));
        }

        return $normalized;
    }

    /**
     * Optional action stage list (print). Empty / null means all stages allowed.
     *
     * @return list<string>|null
     */
    public function normalizeOptionalActionStatuses(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $normalized = $this->normalizeActionStatusList($value);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    protected function normalizeActionStatusList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowed = OrderWorkflowService::actionStageKeys();
        $out = [];
        $seen = [];
        foreach ($value as $status) {
            $key = strtolower(trim((string) $status));
            if ($key === '' || isset($seen[$key]) || ! in_array($key, $allowed, true)) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $key;
        }

        return $out;
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

    protected function normalizePosUiLayout(mixed $layout): string
    {
        $value = strtolower(trim((string) $layout));

        return in_array($value, ['modern', 'classic'], true) ? $value : 'modern';
    }
}
