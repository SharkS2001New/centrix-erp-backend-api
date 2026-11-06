<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Erp\SetupProfileMenuService;
use App\Services\OrganizationPlatformConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SetupProfileMenuController extends Controller
{
    public function __construct(
        protected SetupProfileMenuService $menus,
        protected OrganizationPlatformConfigService $platformConfig,
    ) {}

    public function index()
    {
        return response()->json([
            'data' => collect($this->menus->all())
                ->map(fn (array $profile) => array_merge($profile, [
                    'default_sales_platform' => $this->platformConfig->defaultSalesPlatformConfig($profile['key']),
                    'default_payroll_platform' => $this->platformConfig->defaultPayrollPlatformConfig($profile['key']),
                ]))
                ->values(),
        ]);
    }

    public function update(Request $request, string $profileKey)
    {
        if (! in_array($profileKey, $this->menus->profileKeys(), true)) {
            return response()->json(['message' => 'Unknown setup type.'], 404);
        }

        if (! Schema::hasTable('platform_setup_profiles')) {
            return response()->json([
                'message' => 'Setup types are not ready yet. Run the latest database migration.',
            ], 503);
        }

        $actionStatusRule = Rule::in(OrderWorkflowService::actionStageKeys());
        $workflowStatusRule = Rule::in(OrderWorkflowService::ALL_STATUSES);
        $salesRules = [
            'sales_platform' => 'sometimes|array',
            'sales_platform.show_checkout_on_create_order' => 'sometimes|boolean',
            'sales_platform.backoffice_product_search_mode' => 'sometimes|in:indexeddb,live',
            'sales_platform.enable_mobile_orders' => 'sometimes|boolean',
            'sales_platform.mobile_enable_field_attendance' => 'sometimes|boolean',
            'sales_platform.mobile_enable_driver_app' => 'sometimes|boolean',
            'sales_platform.mobile_enable_driver_attendance' => 'sometimes|boolean',
            'sales_platform.enable_mobile_orders_returns_card' => 'sometimes|boolean',
            'sales_platform.enable_mobile_orders_payments_card' => 'sometimes|boolean',
            'sales_platform.enable_mobile_orders_expenses_card' => 'sometimes|boolean',
            'sales_platform.append_same_day_customer_orders' => 'sometimes|boolean',
            'sales_platform.enable_mpesa_stk' => 'sometimes|boolean',
            'sales_platform.enable_equity_bank' => 'sometimes|boolean',
            'sales_platform.enable_kra_integration' => 'sometimes|boolean',
            'sales_platform.enable_ai' => 'sometimes|boolean',
            'sales_platform.enable_ai_chat' => 'sometimes|boolean',
            'sales_platform.enable_ai_talk' => 'sometimes|boolean',
            'sales_platform.use_platform_gemini' => 'sometimes|boolean',
            'sales_platform.enable_whatsapp_orders' => 'sometimes|boolean',
            'sales_platform.enable_investors' => 'sometimes|boolean',
            'sales_platform.enable_advanced_data_import' => 'sometimes|boolean',
            'sales_platform.advanced_data_import_pages' => 'sometimes|array',
            'sales_platform.advanced_data_import_pages.*' => 'sometimes|boolean',
            'sales_platform.hospitality_services' => 'sometimes|array',
            'sales_platform.hospitality_services.*' => 'sometimes|boolean',
            'sales_platform.hospitality_payment_workflow' => 'sometimes|array',
            'sales_platform.hospitality_payment_workflow.*' => 'sometimes|boolean',
            'sales_platform.hotel_pos_payment_methods' => 'sometimes|array',
            'sales_platform.hotel_pos_payment_methods.*' => 'sometimes|boolean',
            'sales_platform.hotel_pos_collect_payment' => 'sometimes|boolean',
            'sales_platform.stock_deduct_on' => 'sometimes|array',
            'sales_platform.stock_deduct_on.*' => 'sometimes|in:order_created,order_completed,trip_pick,trip_load,trip_depart',
            'sales_platform.reserve_stock_on_cart' => 'sometimes|boolean',
            'sales_platform.cart_reservation_ttl_minutes' => 'sometimes|integer|min:0|max:15',
            'sales_platform.enable_receive_batch_tracking' => 'sometimes|boolean',
            'sales_platform.orders_list_default_days' => 'sometimes|integer|min:1|max:365',
            'sales_platform.shop_debtors_default_days' => 'sometimes|integer|min:1|max:365',
            'sales_platform.reports_default_date_range_days' => 'sometimes|integer|min:1|max:365',
            'sales_platform.orders_list_search_days' => 'sometimes|integer|min:1|max:365',
            'sales_platform.orders_list_sort' => 'sometimes|in:-created_at,created_at,-order_num,order_num',
            'sales_platform.orders_list_visible_columns' => 'sometimes|array',
            'sales_platform.orders_list_visible_columns.*' => 'sometimes|string|max:80',
            'sales_platform.orders_list_visible_columns_by_queue' => 'sometimes|array',
            'sales_platform.orders_list_visible_columns_by_queue.*' => 'sometimes|array',
            'sales_platform.orders_list_visible_columns_by_queue.*.*' => 'sometimes|string|max:80',
            'sales_platform.order_expiry_enabled' => 'sometimes|boolean',
            'sales_platform.order_expiry_days' => 'sometimes|integer|min:1|max:90',
            'sales_platform.order_expiry_before_status' => ['sometimes', 'string', $workflowStatusRule],
            'sales_platform.order_cancellation_enabled' => 'sometimes|boolean',
            'sales_platform.order_workflow' => 'sometimes|array',
            'sales_platform.order_workflow.steps' => 'sometimes|array|min:1',
            'sales_platform.order_workflow.steps.*.status' => ['required_with:sales_platform.order_workflow.steps', 'string', $workflowStatusRule],
            'sales_platform.order_workflow.steps.*.label' => 'sometimes|string|max:60',
            'sales_platform.order_workflow.steps.*.enabled' => 'sometimes|boolean',
            'sales_platform.order_workflow.transitions' => 'sometimes|array',
            'sales_platform.order_workflow.transitions.*' => 'sometimes|array',
            'sales_platform.order_workflow.transitions.*.*' => ['sometimes', 'string', $workflowStatusRule],
            'sales_platform.order_workflow.save_status' => 'sometimes|array',
            'sales_platform.order_workflow.save_status.*' => ['sometimes', 'string', $workflowStatusRule],
            'sales_platform.order_workflow.checkout' => 'sometimes|array',
            'sales_platform.order_workflow.checkout.partial' => ['sometimes', 'string', $workflowStatusRule],
            'sales_platform.order_workflow.checkout.full_paid' => 'sometimes|array',
            'sales_platform.order_workflow.checkout.full_paid.*' => ['sometimes', 'string', $workflowStatusRule],
            'sales_platform.order_workflow.checkout.unpaid' => 'sometimes|array',
            'sales_platform.order_workflow.checkout.unpaid.*' => ['sometimes', 'string', $workflowStatusRule],
            'sales_platform.order_workflow.deduct_stock_on' => 'sometimes|array',
            'sales_platform.order_workflow.deduct_stock_on.*' => ['sometimes', 'string', $workflowStatusRule],
            'sales_platform.order_workflow.reserve_stock_on' => 'sometimes|array',
            'sales_platform.order_workflow.reserve_stock_on.*' => ['sometimes', 'string', $workflowStatusRule],
        ];
        foreach ([
            'edit_order_statuses',
            'print_invoice_statuses',
            'collect_payment_statuses',
            'cancel_order_statuses',
            'convert_to_paid_statuses',
            'convert_to_unpaid_statuses',
            'customer_return_statuses',
        ] as $statusList) {
            $salesRules["sales_platform.{$statusList}"] = 'sometimes|nullable|array';
            $salesRules["sales_platform.{$statusList}.*"] = ['sometimes', 'string', $actionStatusRule];
        }

        $data = $request->validate(array_merge($salesRules, [
            'payroll_platform' => 'sometimes|array',
            'payroll_platform.payroll_month_days_basis' => 'sometimes|in:calendar,fixed_30',
            'payroll_platform.shif_minimum_monthly' => 'sometimes|nullable|numeric|min:0|max:100000',
            'backoffice' => 'sometimes|array',
            'backoffice.*' => 'sometimes|boolean',
            'hr' => 'sometimes|array',
            'hr.*' => 'sometimes|boolean',
            'accounting' => 'sometimes|array',
            'accounting.*' => 'sometimes|boolean',
            'applications' => 'sometimes|array',
            'applications.*' => 'sometimes|boolean',
            'label' => 'sometimes|string|max:80',
        ]));

        try {
            $saved = $this->menus->save($profileKey, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'key' => $profileKey,
                'label' => $saved['label'],
                'industry' => $saved['industry'],
                'custom' => $saved['custom'],
                'menu_groups' => $saved['menu_groups'],
                'applications' => $saved['applications'],
                'sales_platform' => $saved['sales_platform'],
                'payroll_platform' => $saved['payroll_platform'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        if (! Schema::hasTable('platform_setup_profiles')) {
            return response()->json([
                'message' => 'Setup types are not ready yet. Run the latest database migration.',
            ], 503);
        }

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'industry' => ['required', Rule::in(\App\Services\Erp\IndustryRegistry::ids())],
        ]);

        try {
            $row = $this->menus->create($data['name'], $data['industry']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $row], 201);
    }

    public function destroy(string $profileKey)
    {
        try {
            $this->menus->delete($profileKey);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }
}
