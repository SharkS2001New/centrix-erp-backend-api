<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Mpesa\MpesaSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ErpSettingsController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    public function sales(Request $request)
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->organization_id);
        $gate = $this->erp->gateForUser($user);

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
        $org = Organization::findOrFail($user->organization_id);
        $gate = $this->erp->gateForUser($user);

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
            'enable_payment_date',
            'enable_credit_payment',
            'allow_credit_pay_now',
            'show_checkout_on_create_order',
            'enable_checkout_customer_name',
            'retail_shop_wholesale_store_stock',
            'add_route_markup_prices',
            'pos_order_type_mode',
            'enable_mobile_orders',
            'enable_pos_orders',
            'require_pos_till_float',
            'blind_till_close',
            'default_submit_kra',
            'order_document_type',
            'invoice_valid_days',
            'receipt_copies',
            'show_branch_on_receipt',
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
            $rules[$key] = str_starts_with($key, 'default_tax')
                ? 'sometimes|numeric|min:0|max:100'
                : 'sometimes|boolean';
        }

        $data = $request->validate($rules);

        $currentSales = $gate->moduleSettings('sales');
        $nextSales = array_merge($currentSales, array_filter(
            $data,
            fn ($key) => in_array($key, $salesKeys, true),
            ARRAY_FILTER_USE_KEY
        ));

        if (array_key_exists('order_workflow', $data) && is_array($data['order_workflow'])) {
            $workflowService = OrderWorkflowService::forGate($gate);
            $defaults = config('erp.default_order_workflow', []);
            $nextSales['order_workflow'] = $workflowService->normalize(
                $workflowService->mergeWorkflowConfig($defaults, $data['order_workflow']),
            );
        }

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

        if (empty($nextSales['add_route_markup_prices'])) {
            $nextSales['pos_order_type_mode'] = 'normal';
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

        $refreshedGate = $this->erp->gateForUser($user->fresh());
        $refreshed = $refreshedGate->moduleSettings('sales');
        $refreshed['order_workflow'] = OrderWorkflowService::forGate($refreshedGate)->config();
        $system = SystemSetting::query()->where('organization_id', $org->id)->orderBy('id')->first();

        return response()->json([
            'sales' => $refreshed,
            'allow_negative_stock' => (bool) ($system?->allow_below_stock ?? false),
        ]);
    }

    public function finance(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $org = Organization::findOrFail($user->organization_id);
        $finance = $this->mergedFinanceSettings($gate);
        $mpesaConfig = MpesaSettingsResolver::forOrganization($org);
        $finance['mpesa'] = $this->mpesaForResponse($mpesaConfig);
        $finance['mpesa_status'] = MpesaSettingsResolver::describe($mpesaConfig);

        return response()->json(['finance' => $finance]);
    }

    public function updateFinance(Request $request)
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->organization_id);
        $gate = $this->erp->gateForUser($user);

        $data = $request->validate([
            'enable_kra_device' => 'sometimes|boolean',
            'kra_device_ip' => 'sometimes|nullable|string|max:250',
            'kra_serial_number' => 'sometimes|nullable|string|max:100',
            'kra_pin_number' => 'sometimes|nullable|string|max:45',
            'kra_device_test_mode' => 'sometimes|boolean',
            'kra_plu_register_path' => 'sometimes|nullable|string|max:250',
            'default_submit_kra' => 'sometimes|boolean',
            'mpesa' => 'sometimes|array',
            'mpesa.env' => 'sometimes|in:sandbox,live',
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
            'accounting_provider' => 'sometimes|nullable|in:quickbooks,xero,sage',
            'accounting_sync_direction' => 'sometimes|in:export,import,bidirectional',
        ]);

        if (! empty($data['enable_kra_device'])) {
            $ip = trim((string) ($data['kra_device_ip'] ?? $gate->moduleSettings('finance')['kra_device_ip'] ?? ''));
            $serial = trim((string) ($data['kra_serial_number'] ?? $gate->moduleSettings('finance')['kra_serial_number'] ?? ''));
            $pin = trim((string) ($data['kra_pin_number'] ?? $gate->moduleSettings('finance')['kra_pin_number'] ?? ''));
            if ($ip === '' || $serial === '' || $pin === '') {
                throw ValidationException::withMessages([
                    'enable_kra_device' => 'KRA device IP, serial number, and shop PIN are required when the device is enabled.',
                ]);
            }
        }

        $current = $gate->moduleSettings('finance');
        $nextFinance = array_merge($current, array_filter(
            $data,
            fn ($key) => $key !== 'mpesa',
            ARRAY_FILTER_USE_KEY,
        ));

        if (array_key_exists('mpesa', $data) && is_array($data['mpesa'])) {
            $mergedMpesa = MpesaSettingsResolver::mergeFinanceMpesa($current, $data['mpesa']);
            $nextFinance['mpesa'] = $mergedMpesa['mpesa'];
        }

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['finance'] = $nextFinance;
        $org->update(['module_settings' => $moduleSettings]);

        $refreshedGate = $this->erp->gateForUser($user->fresh());
        $finance = $this->mergedFinanceSettings($refreshedGate);
        $mpesaConfig = MpesaSettingsResolver::forOrganization($org->fresh());
        $finance['mpesa'] = $this->mpesaForResponse($mpesaConfig);
        $finance['mpesa_status'] = MpesaSettingsResolver::describe($mpesaConfig);

        return response()->json([
            'finance' => $finance,
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
}
