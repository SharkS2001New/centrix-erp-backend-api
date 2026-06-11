<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
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
            'order_document_type',
            'invoice_valid_days',
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
        ];
        foreach ($salesKeys as $key) {
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
}
