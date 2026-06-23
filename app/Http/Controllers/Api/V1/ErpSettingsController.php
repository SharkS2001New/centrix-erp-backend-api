<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Accounting\QuickBooksSettingsResolver;
use App\Services\Auth\SecuritySettingsResolver;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\GeneralSettingsResolver;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\Legacy\LegacyArchiveReader;
use App\Services\Legacy\OrganizationLegacyArchiveService;
use App\Services\Mpesa\MpesaSettingsResolver;
use App\Services\Notifications\NotificationSettingsResolver;
use App\Services\OrganizationPlatformConfigService;
use App\Services\Purchasing\ProcurementSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ErpSettingsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected OrganizationPlatformConfigService $platformConfig,
    ) {}

    public function sales(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $system = SystemSetting::query()
            ->where('organization_id', $org->id)
            ->orderBy('id')
            ->first();

        $sales = $gate->moduleSettings('sales');
        $sales['order_workflow'] = OrderWorkflowService::forGate($gate)->config();

        return response()->json([
            'sales' => $sales,
            'allow_negative_stock' => (bool) ($system?->allow_below_stock ?? false),
        ]);
    }

    public function updateSales(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $salesKeys = [
            'allow_sell_from_shop',
            'allow_sell_from_store',
            'enable_retail_pricing',
            'allow_discounts',
            'allow_edit_line_discount',
            'enable_order_discount',
            'enable_vouchers',
            'enable_redeemable_points',
            'point_cash_value',
            'points_earn_per_kes',
            'allow_edit_unit_price',
            'enable_barcode_scanner',
            'default_tax_rate',
            'enable_mpesa_amount',
            'enable_mpesa_code',
            'enable_bank_select',
            'enable_equity_bank',
            'enable_kcb_bank',
            'enable_other_bank',
            'other_bank_name',
            'enable_bank_amount',
            'enable_cheque',
            'enable_cheque_number',
            'enable_payment_date',
            'enable_credit_payment',
            'allow_credit_pay_now',
            'show_checkout_on_create_order',
            'enable_checkout_customer_name',
            'retail_shop_wholesale_store_stock',
            'add_route_markup_prices',
            'pos_order_type_mode',
            'enable_mobile_orders',
            'mobile_enable_checkout_location_verification',
            'mobile_allow_offline_orders',
            'mobile_checkout_location_radius_metres',
            'mobile_enable_field_attendance',
            'require_pos_till_float',
            'require_backoffice_till_float',
            'blind_till_close',
            'default_submit_kra',
            'order_document_type',
            'invoice_valid_days',
            'receipt_copies',
            'show_branch_on_receipt',
            'stock_deduct_on',
        ];

        $statusRule = Rule::in(OrderWorkflowService::ALL_STATUSES);

        $rules = [
            'allow_negative_stock' => 'sometimes|boolean',
            'other_bank_name' => 'sometimes|string|max:100',
            'pos_order_type_mode' => 'sometimes|in:normal,route,toggle',
            'order_document_type' => 'sometimes|in:receipt,invoice',
            'invoice_valid_days' => 'sometimes|integer|min:0|max:365',
            'order_workflow' => 'sometimes|array',
            'order_workflow.steps' => 'sometimes|array',
            'order_workflow.steps.*.status' => ['required_with:order_workflow.steps', 'string', $statusRule],
            'order_workflow.steps.*.label' => 'sometimes|string|max:60',
            'order_workflow.steps.*.enabled' => 'sometimes|boolean',
            'order_workflow.transitions' => 'sometimes|array',
            'order_workflow.save_status' => 'sometimes|array',
            'order_workflow.save_status.pos' => ['sometimes', 'string', $statusRule],
            'order_workflow.save_status.mobile' => ['sometimes', 'string', $statusRule],
            'order_workflow.save_status.backend' => ['sometimes', 'string', $statusRule],
            'order_workflow.checkout' => 'sometimes|array',
            'order_workflow.checkout.partial' => ['sometimes', 'string', $statusRule],
            'order_workflow.checkout.full_paid' => 'sometimes|array',
            'order_workflow.checkout.full_paid.pos' => ['sometimes', 'string', $statusRule],
            'order_workflow.checkout.full_paid.mobile' => ['sometimes', 'string', $statusRule],
            'order_workflow.checkout.full_paid.backend' => ['sometimes', 'string', $statusRule],
            'order_workflow.checkout.unpaid' => 'sometimes|array',
            'order_workflow.checkout.unpaid.pos' => ['sometimes', 'string', $statusRule],
            'order_workflow.checkout.unpaid.mobile' => ['sometimes', 'string', $statusRule],
            'order_workflow.checkout.unpaid.backend' => ['sometimes', 'string', $statusRule],
            'order_workflow.deduct_stock_on' => ['sometimes', 'string', $statusRule],
            'receipt_copies' => 'sometimes|integer|min:1|max:10',
            'stock_deduct_on' => 'sometimes|in:order_completed,trip_load,trip_depart',
            'mobile_checkout_location_radius_metres' => 'sometimes|numeric|min:1|max:500',
        ];
        foreach ($salesKeys as $key) {
            // Do not overwrite any rules that were explicitly defined above
            if (array_key_exists($key, $rules)) {
                continue;
            }
            if (in_array($key, ['other_bank_name', 'pos_order_type_mode', 'order_document_type'], true)) {
                continue;
            }
            if (in_array($key, ['point_cash_value', 'points_earn_per_kes'], true)) {
                $rules[$key] = 'sometimes|numeric|min:0';

                continue;
            }
            if ($key === 'mobile_checkout_location_radius_metres') {
                continue;
            }
            $rules[$key] = str_starts_with($key, 'default_tax')
                ? 'sometimes|numeric|min:0|max:100'
                : 'sometimes|boolean';
        }

        $data = $request->validate($rules);

        if (! $user->is_super_admin) {
            $data = $this->platformConfig->filterOrgManagerSalesPayload($data, $gate);
        }

        $currentSales = $gate->moduleSettings('sales');
        $nextSales = array_merge($currentSales, array_filter(
            $data,
            fn ($key) => in_array($key, $salesKeys, true),
            ARRAY_FILTER_USE_KEY
        ));

        if (array_key_exists('order_workflow', $data) && is_array($data['order_workflow']) && $user->is_super_admin) {
            $workflowService = OrderWorkflowService::forGate($gate);
            $defaults = config('erp.default_order_workflow', []);
            $nextSales['order_workflow'] = $workflowService->normalize(
                $workflowService->mergeWorkflowConfig($defaults, $data['order_workflow']),
            );
        }

        $this->assertValidStockSourceSettings($nextSales);
        $this->normalizeStockSourceSettings($nextSales);

        if (empty($nextSales['add_route_markup_prices'])) {
            $nextSales['pos_order_type_mode'] = 'normal';
        }

        if (empty($nextSales['mobile_enable_checkout_location_verification'])) {
            $nextSales['mobile_allow_offline_orders'] = false;
        }

        if (array_key_exists('mobile_checkout_location_radius_metres', $data)) {
            $nextSales['mobile_checkout_location_radius_metres'] = max(
                1,
                min(500, (float) ($data['mobile_checkout_location_radius_metres'] ?? 5)),
            );
        }

        if (! empty($nextSales['allow_credit_pay_now']) && ! empty($nextSales['enable_credit_payment'])) {
            if (array_key_exists('enable_credit_payment', $data) && ($data['enable_credit_payment'] ?? false)) {
                $nextSales['allow_credit_pay_now'] = false;
            } else {
                $nextSales['enable_credit_payment'] = false;
            }
        }

        if (array_key_exists('other_bank_name', $data)) {
            $name = trim((string) $data['other_bank_name']);
            $nextSales['other_bank_name'] = $name !== '' ? $name : 'Other bank';
        }

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['sales'] = $nextSales;
        $org->update(['module_settings' => $moduleSettings]);

        if (array_key_exists('allow_negative_stock', $data)) {
            $system = SystemSetting::query()->firstOrCreate(
                ['organization_id' => $org->id],
                ['allow_below_stock' => 0, 'stock_alert_mode' => 'per_product'],
            );
            $system->update(['allow_below_stock' => $data['allow_negative_stock'] ? 1 : 0]);
        }

        $refreshedGate = $this->erp->gateForOrganization($org->fresh());
        $refreshed = $refreshedGate->moduleSettings('sales');
        $refreshed['order_workflow'] = OrderWorkflowService::forGate($refreshedGate)->config();
        $system = SystemSetting::query()->where('organization_id', $org->id)->orderBy('id')->first();

        return response()->json([
            'sales' => $refreshed,
            'allow_negative_stock' => (bool) ($system?->allow_below_stock ?? false),
        ]);
    }

    public function distribution(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForRequest($request);

        return response()->json([
            'distribution' => $gate->distributionSettings(),
        ]);
    }

    public function updateDistribution(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $distributionKeys = [
            'enable_distribution_ops',
            'inherit_customer_route',
            'assign_on_status',
            'auto_assign_truck',
            'auto_assign_driver',
            'require_weight_on_load',
            'set_delivery_date_on',
            'require_pod_on_delivered',
            'enforce_vehicle_capacity',
            'enable_cod_reconciliation',
            'require_trip_cash_settlement',
            'include_normal_orders_in_loading_list',
        ];

        $statusRule = Rule::in(OrderWorkflowService::ALL_STATUSES);

        $rules = [
            'assign_on_status' => ['sometimes', 'string', $statusRule],
            'set_delivery_date_on' => ['sometimes', 'string', $statusRule],
        ];
        foreach ($distributionKeys as $key) {
            if (array_key_exists($key, $rules)) {
                continue;
            }
            $rules[$key] = 'sometimes|boolean';
        }

        $data = $request->validate($rules);

        if (! $user->is_super_admin) {
            $data = $this->platformConfig->filterOrgManagerDistributionPayload($data);
        }

        $current = $gate->distributionSettings();
        $next = array_merge($current, array_filter(
            $data,
            fn ($key) => in_array($key, $distributionKeys, true),
            ARRAY_FILTER_USE_KEY
        ));

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['distribution'] = $next;
        $org->update(['module_settings' => $moduleSettings]);

        $refreshedGate = $this->erp->gateForOrganization($org->fresh());

        return response()->json([
            'distribution' => $refreshedGate->distributionSettings(),
        ]);
    }

    public function inventory(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        $sales = $gate->moduleSettings('sales');
        $inventory = $gate->moduleSettings('inventory');
        $system = SystemSetting::query()
            ->where('organization_id', $org->id)
            ->orderBy('id')
            ->first();

        return response()->json([
            'inventory' => $this->inventorySettingsResponse($inventory, $sales, $system),
        ]);
    }

    public function updateInventory(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $inventoryKeys = [
            'default_receive_location',
            'default_pos_sale_location',
            'default_distribution_sale_location',
            'reserve_stock_on_cart',
            'cart_reservation_ttl_minutes',
        ];

        $stockSourceKeys = [
            'allow_sell_from_shop',
            'allow_sell_from_store',
            'enable_retail_pricing',
            'retail_shop_wholesale_store_stock',
            'enable_barcode_scanner',
        ];

        $data = $request->validate([
            'default_receive_location' => 'sometimes|in:shop,store',
            'default_pos_sale_location' => 'sometimes|in:shop,store',
            'default_distribution_sale_location' => 'sometimes|in:shop,store',
            'reserve_stock_on_cart' => 'sometimes|boolean',
            'cart_reservation_ttl_minutes' => 'sometimes|integer|min:0|max:15',
            'allow_sell_from_shop' => 'sometimes|boolean',
            'allow_sell_from_store' => 'sometimes|boolean',
            'enable_retail_pricing' => 'sometimes|boolean',
            'retail_shop_wholesale_store_stock' => 'sometimes|boolean',
            'enable_barcode_scanner' => 'sometimes|boolean',
            'allow_negative_stock' => 'sometimes|boolean',
            'stock_alert_mode' => 'sometimes|in:per_product,global,both',
            'global_low_stock_threshold' => 'sometimes|nullable|numeric|min:0',
        ]);

        $currentSales = $gate->moduleSettings('sales');
        $nextSales = array_merge($currentSales, array_filter(
            $data,
            fn ($key) => in_array($key, $stockSourceKeys, true),
            ARRAY_FILTER_USE_KEY
        ));

        $this->assertValidStockSourceSettings($nextSales);
        $this->normalizeStockSourceSettings($nextSales);

        $currentInventory = $gate->moduleSettings('inventory');
        $nextInventory = array_merge($currentInventory, array_filter(
            $data,
            fn ($key) => in_array($key, $inventoryKeys, true),
            ARRAY_FILTER_USE_KEY
        ));

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['sales'] = $nextSales;
        $moduleSettings['inventory'] = $nextInventory;
        $org->update(['module_settings' => $moduleSettings]);

        $systemPayload = array_filter(
            $data,
            fn ($key) => in_array($key, ['allow_negative_stock', 'stock_alert_mode', 'global_low_stock_threshold'], true),
            ARRAY_FILTER_USE_KEY
        );
        if ($systemPayload !== []) {
            $system = SystemSetting::query()->firstOrCreate(
                ['organization_id' => $org->id],
                ['allow_below_stock' => 0, 'stock_alert_mode' => 'per_product'],
            );
            $updates = [];
            if (array_key_exists('allow_negative_stock', $systemPayload)) {
                $updates['allow_below_stock'] = $systemPayload['allow_negative_stock'] ? 1 : 0;
            }
            if (array_key_exists('stock_alert_mode', $systemPayload)) {
                $updates['stock_alert_mode'] = $systemPayload['stock_alert_mode'];
            }
            if (array_key_exists('global_low_stock_threshold', $systemPayload)) {
                $updates['global_low_stock_threshold'] = $systemPayload['global_low_stock_threshold'];
            }
            if ($updates !== []) {
                $system->update($updates);
            }
        }

        $refreshedGate = $this->erp->gateForOrganization($org->fresh());
        $system = SystemSetting::query()->where('organization_id', $org->id)->orderBy('id')->first();

        return response()->json([
            'inventory' => $this->inventorySettingsResponse(
                $refreshedGate->moduleSettings('inventory'),
                $refreshedGate->moduleSettings('sales'),
                $system,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    protected function inventorySettingsResponse(array $inventory, array $sales, ?SystemSetting $system): array
    {
        return array_merge($inventory, [
            'allow_sell_from_shop' => (bool) ($sales['allow_sell_from_shop'] ?? false),
            'allow_sell_from_store' => (bool) ($sales['allow_sell_from_store'] ?? false),
            'enable_retail_pricing' => (bool) ($sales['enable_retail_pricing'] ?? false),
            'retail_shop_wholesale_store_stock' => (bool) ($sales['retail_shop_wholesale_store_stock'] ?? false),
            'enable_barcode_scanner' => (bool) ($sales['enable_barcode_scanner'] ?? false),
            'allow_negative_stock' => (bool) ($system?->allow_below_stock ?? false),
            'stock_alert_mode' => $system?->stock_alert_mode ?? 'per_product',
            'global_low_stock_threshold' => $system?->global_low_stock_threshold,
        ]);
    }

    /** @param  array<string, mixed>  $nextSales */
    protected function assertValidStockSourceSettings(array $nextSales): void
    {
        if (
            empty($nextSales['allow_sell_from_shop'])
            && empty($nextSales['allow_sell_from_store'])
            && (
                empty($nextSales['enable_retail_pricing'])
                || empty($nextSales['retail_shop_wholesale_store_stock'])
            )
        ) {
            throw ValidationException::withMessages([
                'allow_sell_from_shop' => 'Enable shop stock, store stock, or retail-from-shop / wholesale-from-store routing.',
            ]);
        }

        if (
            ! empty($nextSales['allow_sell_from_shop'])
            && ! empty($nextSales['allow_sell_from_store'])
        ) {
            throw ValidationException::withMessages([
                'allow_sell_from_shop' => 'Enable only shop stock or store stock — not both at the same time.',
            ]);
        }
    }

    /** @param  array<string, mixed>  $nextSales */
    protected function normalizeStockSourceSettings(array &$nextSales): void
    {
        if (! empty($nextSales['retail_shop_wholesale_store_stock'])) {
            $nextSales['allow_sell_from_shop'] = false;
            $nextSales['allow_sell_from_store'] = false;
        }

        if (empty($nextSales['enable_retail_pricing'])) {
            $nextSales['retail_shop_wholesale_store_stock'] = false;
            if (
                empty($nextSales['allow_sell_from_shop'])
                && empty($nextSales['allow_sell_from_store'])
            ) {
                $nextSales['allow_sell_from_shop'] = true;
                $nextSales['allow_sell_from_store'] = false;
            }
        }
    }

    public function finance(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForRequest($request);
        $org = $this->erp->resolveOrganization($request);
        $finance = $this->mergedFinanceSettings($gate);
        $mpesaConfig = MpesaSettingsResolver::forOrganization($org);
        $finance['mpesa'] = $this->mpesaForResponse($mpesaConfig);
        $finance['mpesa_status'] = MpesaSettingsResolver::describe($mpesaConfig);
        $qbStored = is_array($finance['quickbooks'] ?? null) ? $finance['quickbooks'] : [];
        $finance['quickbooks'] = QuickBooksSettingsResolver::maskStoredForClient($qbStored);
        $finance['quickbooks_status'] = QuickBooksSettingsResolver::describe(
            QuickBooksSettingsResolver::forOrganization($org)
        );

        return response()->json(['finance' => $this->sanitizeFinanceForClient($finance, $gate)]);
    }

    public function updateFinance(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $data = $request->validate([
            'enable_mpesa_stk' => 'sometimes|boolean',
            'enable_kra_integration' => 'sometimes|boolean',
            'enable_kra_device' => 'sometimes|boolean',
            'kra_device_ip' => 'sometimes|nullable|string|max:250',
            'kra_serial_number' => 'sometimes|nullable|string|max:100',
            'kra_pin_number' => 'sometimes|nullable|string|max:45',
            'kra_device_test_mode' => 'sometimes|boolean',
            'kra_plu_register_path' => 'sometimes|nullable|string|max:250',
            'default_submit_kra' => 'sometimes|boolean',
            'mpesa' => 'sometimes|array',
            'mpesa.env' => 'sometimes|in:sandbox,live',
            'mpesa.enable_stk_push' => 'sometimes|boolean',
            'mpesa.consumer_key' => 'sometimes|nullable|string|max:250',
            'mpesa.consumer_secret' => 'sometimes|nullable|string|max:250',
            'mpesa.shortcode' => 'sometimes|nullable|string|max:20',
            'mpesa.till_number' => 'sometimes|nullable|string|max:20',
            'mpesa.child_storecode' => 'sometimes|nullable|string|max:20',
            'mpesa.passkey' => 'sometimes|nullable|string|max:250',
            'mpesa.stk_callback_url' => 'sometimes|nullable|string|max:500',
            'mpesa.c2b_confirmation_url' => 'sometimes|nullable|string|max:500',
            'mpesa.c2b_validation_url' => 'sometimes|nullable|string|max:500',
            'accounting_mode' => 'sometimes|in:native,external',
            'accounting_provider' => 'sometimes|nullable|in:quickbooks',
            'accounting_sync_direction' => 'sometimes|in:export,import,bidirectional',
            'quickbooks' => 'sometimes|array',
            'quickbooks.client_id' => 'sometimes|nullable|string|max:250',
            'quickbooks.client_secret' => 'sometimes|nullable|string|max:250',
            'quickbooks.redirect_uri' => 'sometimes|nullable|string|max:500',
            'quickbooks.environment' => 'sometimes|in:sandbox,production',
        ]);

        if (! $user->is_super_admin) {
            $data = $this->platformConfig->filterOrgManagerFinancePayload($data);
        }

        if (! empty($data['enable_kra_device'])) {
            if (! $gate->kraIntegrationPlatformEnabled()) {
                throw ValidationException::withMessages([
                    'enable_kra_device' => ['KRA integration is not enabled for this organization by the platform administrator.'],
                ]);
            }
            $ip = trim((string) ($data['kra_device_ip'] ?? $gate->moduleSettings('finance')['kra_device_ip'] ?? ''));
            $serial = trim((string) ($data['kra_serial_number'] ?? $gate->moduleSettings('finance')['kra_serial_number'] ?? ''));
            $pin = trim((string) ($data['kra_pin_number'] ?? $gate->moduleSettings('finance')['kra_pin_number'] ?? ''));
            if ($ip === '' || $serial === '' || $pin === '') {
                throw ValidationException::withMessages([
                    'enable_kra_device' => 'KRA device IP, serial number, and shop PIN are required when the device is enabled.',
                ]);
            }
        }

        if (array_key_exists('mpesa', $data) && is_array($data['mpesa']) && ! $gate->mpesaStkPlatformEnabled()) {
            throw ValidationException::withMessages([
                'mpesa' => ['M-Pesa STK Push is not enabled for this organization by the platform administrator.'],
            ]);
        }

        $current = $gate->moduleSettings('finance');
        $nextFinance = array_merge($current, array_filter(
            $data,
            fn ($key) => ! in_array($key, ['mpesa', 'quickbooks'], true),
            ARRAY_FILTER_USE_KEY,
        ));

        if (array_key_exists('mpesa', $data) && is_array($data['mpesa'])) {
            $mergedMpesa = MpesaSettingsResolver::mergeFinanceMpesa($current, $data['mpesa']);
            $nextFinance['mpesa'] = $mergedMpesa['mpesa'];
        }

        if (array_key_exists('quickbooks', $data) && is_array($data['quickbooks'])) {
            $mergedQuickBooks = QuickBooksSettingsResolver::mergeFinanceQuickBooks($current, $data['quickbooks']);
            $nextFinance['quickbooks'] = $mergedQuickBooks['quickbooks'];
        }

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['finance'] = $nextFinance;
        $org->update(['module_settings' => $moduleSettings]);

        $refreshedGate = $this->erp->gateForOrganization($org->fresh());
        $finance = $this->mergedFinanceSettings($refreshedGate);
        $mpesaConfig = MpesaSettingsResolver::forOrganization($org->fresh());
        $finance['mpesa'] = $this->mpesaForResponse($mpesaConfig);
        $finance['mpesa_status'] = MpesaSettingsResolver::describe($mpesaConfig);
        $qbStored = is_array($finance['quickbooks'] ?? null) ? $finance['quickbooks'] : [];
        $finance['quickbooks'] = QuickBooksSettingsResolver::maskStoredForClient($qbStored);
        $finance['quickbooks_status'] = QuickBooksSettingsResolver::describe(
            QuickBooksSettingsResolver::forOrganization($org->fresh())
        );

        return response()->json([
            'finance' => $this->sanitizeFinanceForClient($finance, $refreshedGate),
        ]);
    }

    /** @param  array<string, mixed>  $mpesa */
    protected function mpesaForResponse(array $mpesa): array
    {
        return MpesaSettingsResolver::maskForClient($mpesa);
    }

    protected function mergedFinanceSettings(CapabilityGate $gate): array
    {
        $finance = $gate->moduleSettings('finance');
        $sales = $gate->moduleSettings('sales');

        if (! array_key_exists('default_submit_kra', $finance) && array_key_exists('default_submit_kra', $sales)) {
            $finance['default_submit_kra'] = (bool) $sales['default_submit_kra'];
        }

        return $finance;
    }

    public function general(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForRequest($request);

        return response()->json([
            'general' => GeneralSettingsResolver::forGate($gate),
        ]);
    }

    public function updateGeneral(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $data = $request->validate([
            'currency' => 'sometimes|string|max:10',
            'timezone' => 'sometimes|string|max:64',
            'date_format' => 'sometimes|in:DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD',
            'language' => 'sometimes|in:en,sw',
            'decimal_places' => 'sometimes|integer|min:0|max:4',
            'number_thousands_separator' => 'sometimes|in:comma,space,none',
            'fiscal_year_start_month' => 'sometimes|integer|min:1|max:12',
            'week_starts_on' => 'sometimes|in:monday,sunday',
            'phone_country_code' => 'sometimes|string|max:8',
            'default_country_code' => 'sometimes|string|max:4',
            'document_footer_text' => 'sometimes|nullable|string|max:500',
            'show_organization_on_documents' => 'sometimes|boolean',
        ]);

        $next = GeneralSettingsResolver::normalize(array_merge(
            $gate->moduleSettings('general'),
            $data,
        ));
        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['general'] = $next;
        $org->update(['module_settings' => $moduleSettings]);

        return response()->json([
            'general' => GeneralSettingsResolver::forGate(
                $this->erp->gateForOrganization($org->fresh()),
            ),
        ]);
    }

    public function notifications(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);
        $settings = NotificationSettingsResolver::forGate($gate);

        return response()->json([
            'organization_id' => $org->id,
            'organization_name' => $org->org_name,
            'notifications' => NotificationSettingsResolver::maskForClient($settings),
            'notifications_status' => NotificationSettingsResolver::describe($settings, $org),
            'mail_from' => NotificationSettingsResolver::mailFrom($org, $settings),
        ]);
    }

    public function updateNotifications(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $data = $request->validate([
            'sms_enabled' => 'sometimes|boolean',
            'sms_provider' => 'sometimes|in:africas_talking',
            'africas_talking_username' => 'sometimes|nullable|string|max:120',
            'africas_talking_api_key' => 'sometimes|nullable|string|max:250',
            'africas_talking_sender_id' => 'sometimes|nullable|string|max:20',
            'email_enabled' => 'sometimes|boolean',
            'email_from_name' => 'sometimes|nullable|string|max:120',
            'email_from_address' => 'sometimes|nullable|email|max:250',
            'smtp_enabled' => 'sometimes|boolean',
            'smtp_host' => 'sometimes|nullable|string|max:200',
            'smtp_port' => 'sometimes|integer|min:1|max:65535',
            'smtp_username' => 'sometimes|nullable|string|max:200',
            'smtp_password' => 'sometimes|nullable|string|max:250',
            'smtp_encryption' => 'sometimes|in:tls,ssl,none',
            'notify_on_dispatch' => 'sometimes|boolean',
            'notify_on_delivery' => 'sometimes|boolean',
            'notify_on_order_placed' => 'sometimes|boolean',
            'order_placed_scope' => 'sometimes|in:all,debtors,route_orders',
            'notify_on_debtor_payment' => 'sometimes|boolean',
            'debtor_payment_scope' => 'sometimes|in:all,debtors,route_orders',
            'dispatch_sms_template' => 'sometimes|nullable|string|max:500',
            'delivery_sms_template' => 'sometimes|nullable|string|max:500',
            'dispatch_email_template' => 'sometimes|nullable|string|max:500',
            'delivery_email_template' => 'sometimes|nullable|string|max:500',
            'order_placed_sms_template' => 'sometimes|nullable|string|max:500',
            'order_placed_email_template' => 'sometimes|nullable|string|max:500',
            'debtor_payment_sms_template' => 'sometimes|nullable|string|max:500',
            'debtor_payment_email_template' => 'sometimes|nullable|string|max:500',
        ]);

        $current = $gate->moduleSettings('notifications');
        $merged = NotificationSettingsResolver::mergeStored($current, $data);
        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['notifications'] = $merged['notifications'];
        $org->update(['module_settings' => $moduleSettings]);

        $refreshed = NotificationSettingsResolver::forOrganization($org->fresh());

        return response()->json([
            'organization_id' => $org->id,
            'organization_name' => $org->org_name,
            'notifications' => NotificationSettingsResolver::maskForClient($refreshed),
            'notifications_status' => NotificationSettingsResolver::describe($refreshed, $org),
            'mail_from' => NotificationSettingsResolver::mailFrom($org, $refreshed),
        ]);
    }

    public function procurement(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForRequest($request);

        return response()->json([
            'procurement' => ProcurementSettingsResolver::forGate($gate),
        ]);
    }

    public function updateProcurement(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $procurementKeys = [
            'default_payment_terms_days',
            'require_lpo_approval',
            'default_receive_location',
            'auto_email_supplier_on_lpo',
        ];

        $rules = [
            'default_payment_terms_days' => 'sometimes|integer|min:0|max:365',
            'default_receive_location' => 'sometimes|in:shop,store',
        ];
        foreach ($procurementKeys as $key) {
            if (array_key_exists($key, $rules)) {
                continue;
            }
            $rules[$key] = 'sometimes|boolean';
        }

        $data = $request->validate($rules);
        $next = ProcurementSettingsResolver::normalize(array_merge(
            $gate->moduleSettings('procurement'),
            array_filter(
                $data,
                fn ($key) => in_array($key, $procurementKeys, true),
                ARRAY_FILTER_USE_KEY,
            ),
        ));

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['procurement'] = $next;
        $org->update(['module_settings' => $moduleSettings]);

        return response()->json([
            'procurement' => ProcurementSettingsResolver::forGate(
                $this->erp->gateForOrganization($org->fresh()),
            ),
        ]);
    }

    public function security(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForRequest($request);

        return response()->json([
            'security' => SecuritySettingsResolver::forGate($gate),
        ]);
    }

    public function updateSecurity(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $data = $request->validate([
            'screen_lock_minutes' => 'sometimes|integer|min:1|max:120',
            'session_idle_minutes' => 'sometimes|integer|min:5|max:480',
            'require_strong_passwords' => 'sometimes|boolean',
            'password_min_length' => 'sometimes|integer|min:6|max:128',
        ]);

        $current = $gate->moduleSettings('security');
        $screenLock = (int) ($data['screen_lock_minutes'] ?? $current['screen_lock_minutes'] ?? 5);
        $sessionIdle = (int) ($data['session_idle_minutes'] ?? $current['session_idle_minutes'] ?? 60);
        if ($screenLock >= $sessionIdle) {
            throw ValidationException::withMessages([
                'screen_lock_minutes' => ['Screen lock must be less than the sign-out timeout.'],
            ]);
        }

        $next = SecuritySettingsResolver::normalize(array_merge(
            $current,
            $data,
        ));
        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['security'] = $next;
        $org->update(['module_settings' => $moduleSettings]);

        return response()->json([
            'security' => SecuritySettingsResolver::forOrganization($org->fresh()),
        ]);
    }

    public function legacyArchive(Request $request, OrganizationLegacyArchiveService $legacySettings, LegacyArchiveReader $archive)
    {
        $org = $this->erp->resolveOrganization($request);
        $settings = $legacySettings->maskForClient($legacySettings->forOrganization($org));

        return response()->json([
            'legacy_archive' => $settings,
            'legacy_archive_status' => $archive->status($org),
        ]);
    }

    public function updateLegacyArchive(Request $request, OrganizationLegacyArchiveService $legacySettings, LegacyArchiveReader $archive)
    {
        $org = $this->erp->resolveOrganization($request);

        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'database' => 'sometimes|nullable|string|max:120',
            'host' => 'sometimes|nullable|string|max:200',
            'port' => 'sometimes|nullable|integer|min:1|max:65535',
            'username' => 'sometimes|nullable|string|max:120',
            'password' => 'sometimes|nullable|string|max:250',
            'label' => 'sometimes|nullable|string|max:120',
            'cutover_date' => 'sometimes|nullable|date',
            'legacy_company_code' => 'sometimes|nullable|string|max:45',
        ]);

        $org = $legacySettings->updateOrganization($org, $data);
        $settings = $legacySettings->maskForClient($legacySettings->forOrganization($org));

        return response()->json([
            'legacy_archive' => $settings,
            'legacy_archive_status' => $archive->status($org),
        ]);
    }

    public function hr(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForRequest($request);

        return response()->json([
            'hr_payroll' => HrPayrollSettingsResolver::forGate($gate),
        ]);
    }

    public function updateHr(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $hrKeys = [
            'pay_frequency',
            'grace_days_after_month_end',
            'payroll_run_delete_lock_minutes',
            'auto_calculate_statutory',
            'close_cycle_on_process',
            'include_overtime_in_payroll',
            'include_other_deductions_in_payroll',
            'require_payroll_approval',
            'require_attendance_for_payroll',
            'standard_work_hours_per_day',
            'overtime_rate_multiplier',
            'default_probation_months',
            'enable_cash_advance_deductions',
            'deduct_cash_advances_on_payroll',
            'attendance_capture_mode',
            'company_premises_latitude',
            'company_premises_longitude',
            'company_premises_radius_metres',
            'company_face_match_threshold',
            'company_fingerprint_match_threshold',
            'company_mobile_verification_method',
        ];

        $rules = [
            'pay_frequency' => 'sometimes|in:monthly',
            'grace_days_after_month_end' => 'sometimes|integer|min:1|max:31',
            'payroll_run_delete_lock_minutes' => 'sometimes|integer|min:1|max:1440',
            'standard_work_hours_per_day' => 'sometimes|numeric|min:1|max:24',
            'overtime_rate_multiplier' => 'sometimes|numeric|min:1|max:5',
            'default_probation_months' => 'sometimes|integer|min:0|max:24',
            'attendance_capture_mode' => 'sometimes|in:clock_device,company_mobile',
            'company_premises_latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'company_premises_longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'company_premises_radius_metres' => 'sometimes|numeric|min:1|max:500',
            'company_face_match_threshold' => 'sometimes|numeric|min:0.5|max:0.99',
            'company_fingerprint_match_threshold' => 'sometimes|numeric|min:0.5|max:0.99',
            'company_mobile_verification_method' => 'sometimes|in:face,fingerprint,face_or_fingerprint,device_biometric,face_or_device_biometric',
        ];
        foreach ($hrKeys as $key) {
            if (array_key_exists($key, $rules)) {
                continue;
            }
            $rules[$key] = 'sometimes|boolean';
        }

        $data = $request->validate($rules);
        $next = HrPayrollSettingsResolver::normalize(array_merge(
            $gate->moduleSettings('hr_payroll'),
            array_filter(
                $data,
                fn ($key) => in_array($key, $hrKeys, true),
                ARRAY_FILTER_USE_KEY,
            ),
        ));

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['hr_payroll'] = $next;
        $org->update(['module_settings' => $moduleSettings]);

        return response()->json([
            'hr_payroll' => HrPayrollSettingsResolver::forOrganization($org->fresh()),
        ]);
    }

    /** @param  array<string, mixed>  $finance */
    protected function sanitizeFinanceForClient(array $finance, CapabilityGate $gate): array
    {
        if (! $gate->kraIntegrationPlatformEnabled()) {
            foreach ([
                'enable_kra_device', 'kra_device_ip', 'kra_serial_number', 'kra_pin_number',
                'kra_device_test_mode', 'kra_plu_register_path', 'default_submit_kra',
            ] as $key) {
                unset($finance[$key]);
            }
        }

        if (! $gate->mpesaStkPlatformEnabled()) {
            unset($finance['mpesa'], $finance['mpesa_status']);
        }

        if (! empty($finance['kra_pin_number'])) {
            $finance['kra_pin_number'] = '********';
        }

        return $finance;
    }
}
