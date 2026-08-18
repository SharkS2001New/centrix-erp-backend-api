<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\UserDeviceToken;
use App\Services\Auth\UserMobileOrderScopeService;
use App\Services\Customers\MobileCustomerService;
use App\Services\Mobile\UserDeviceTokenService;
use App\Services\Sales\MobileRouteExpenseService;
use App\Services\Sales\MobileSalesService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MobileSalesController extends Controller
{
    public function __construct(
        protected MobileSalesService $mobileSales,
        protected MobileRouteExpenseService $routeExpenses,
        protected MobileCustomerService $mobileCustomers,
        protected UserMobileOrderScopeService $mobileScope,
        protected UserDeviceTokenService $deviceTokens,
    ) {}

    /** GET /mobile/dashboard — rep-scoped KPIs and charts for the mobile app. */
    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'all_channels' => 'nullable|boolean',
        ]);

        $to = isset($data['to_date'])
            ? Carbon::parse($data['to_date'])->startOfDay()
            : now()->startOfDay();
        $from = isset($data['from_date'])
            ? Carbon::parse($data['from_date'])->startOfDay()
            : $to->copy();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->mobileSales->dashboard($request->user(), $from, $to, $allChannels),
        );
    }

    /** GET /mobile/reconciliation — current-month sales by day and week for reps. */
    public function reconciliation(Request $request)
    {
        $data = $request->validate([
            'all_channels' => 'nullable|boolean',
        ]);

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->mobileSales->reconciliation($request->user(), $allChannels),
        );
    }

    /** GET /mobile/expenses — the signed-in rep's route expenses. */
    public function indexExpenses(Request $request)
    {
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        return response()->json([
            'data' => $this->routeExpenses->listForRep(
                $request->user(),
                $data['from_date'] ?? null,
                $data['to_date'] ?? null,
            ),
        ]);
    }

    /** POST /mobile/expenses — submit a route expense for manager approval. */
    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'description' => 'required|string|max:200',
            'expense_amount' => 'required|numeric|min:0.01',
            'expense_date' => 'nullable|date',
        ]);

        return response()->json(
            $this->routeExpenses->createForRep($request->user(), $data),
            201,
        );
    }

    /** GET /mobile/orders — paginated mobile orders for the signed-in rep. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'status' => 'nullable|string|max:40',
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
            'all_channels' => 'nullable|boolean',
        ]);

        if (array_key_exists('all_channels', $filters)) {
            $filters['all_channels'] = filter_var(
                $filters['all_channels'],
                FILTER_VALIDATE_BOOLEAN,
            );
        }

        return response()->json(
            $this->mobileSales->listOrders($request->user(), $filters),
        );
    }

    /** GET /mobile/orders/{saleId} — order header + line items. */
    public function show(Request $request, int $saleId)
    {
        $data = $request->validate([
            'all_channels' => 'nullable|boolean',
        ]);

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->mobileSales->showOrder($request->user(), $saleId, $allChannels),
        );
    }

    /** PATCH /mobile/orders/{saleId}/editable-lines — revise qty/discount on a rejected editable order. */
    public function updateEditableLines(Request $request, int $saleId)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.discount_given' => 'sometimes|numeric|min:0',
            'all_channels' => 'nullable|boolean',
        ]);

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->mobileSales->updateEditableOrderLines(
                $request->user(),
                $saleId,
                $data['items'],
                $allChannels,
            ),
        );
    }

    /** POST /mobile/orders/{saleId}/returns — line or full-order return pending manager approval. */
    public function storeReturn(Request $request, int $saleId)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:200',
            'stock_location' => 'nullable|in:shop,store',
            'full_order' => 'sometimes|boolean',
            'all_channels' => 'nullable|boolean',
            'lines' => 'sometimes|array|min:1',
            'lines.*.product_code' => 'required_with:lines|string',
            'lines.*.return_qty' => 'required_with:lines|numeric|min:0.0001',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.sale_item_id' => 'nullable|integer',
        ]);

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->mobileSales->createOrderReturn(
                $request->user(),
                $saleId,
                $data,
                $allChannels,
            ),
            201,
        );
    }

    /** POST /mobile/orders/{saleId}/payments — collect full or partial payment on an order. */
    public function storePayment(Request $request, int $saleId)
    {
        $data = $request->validate([
            'payment_method_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:120',
            'all_channels' => 'nullable|boolean',
        ]);

        $allChannels = filter_var($data['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $sale = $this->mobileSales->collectOrderPayment(
            $request->user(),
            $saleId,
            $data,
            $allChannels,
        );

        return response()->json(
            $this->mobileSales->showOrder($request->user(), (int) $sale->id, $allChannels),
        );
    }

    /** GET /mobile/routes — active routes available to the signed-in mobile rep. */
    public function indexRoutes(Request $request)
    {
        $routes = $this->mobileScope->listRoutesForUser($request->user());

        $assignedRouteIds = $this->mobileScope->assignedRouteIds($request->user());

        return response()->json([
            'data' => $routes->map(static fn ($route) => [
                'id' => (int) $route->id,
                'route_name' => $route->route_name,
                'branch_id' => $route->branch_id !== null ? (int) $route->branch_id : null,
                'route_markup_price' => $route->route_markup_price,
            ])->values(),
            'route_selection_locked' => $this->mobileScope->isRouteSelectionLocked($request->user()),
            'assigned_route_id' => $assignedRouteIds[0] ?? null,
            'assigned_route_ids' => $assignedRouteIds,
        ]);
    }

    /** GET /mobile/customers — ERP customers for the signed-in rep. */
    public function indexCustomers(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
            'route_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->mobileCustomers->list($request->user(), $filters),
        );
    }

    /** GET /mobile/customers/{customerNum} */
    public function showCustomer(Request $request, int $customerNum)
    {
        return response()->json(
            $this->mobileCustomers->show($request->user(), $customerNum),
        );
    }

    /** POST /mobile/customers */
    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_type' => 'nullable|in:debtor,route,regular',
            'phone_number' => 'nullable|string|max:45',
            'additional_phone' => 'nullable|string|max:45',
            'email' => 'nullable|email|max:255',
            'town' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'route_id' => 'nullable|integer|min:1',
            'kra_pin' => 'nullable|string|max:45',
            'terms_of_payment' => 'nullable|string|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
            'branch_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->mobileCustomers->store($request->user(), $data),
            201,
        );
    }

    /** PUT /mobile/customers/{customerNum} */
    public function updateCustomer(Request $request, int $customerNum)
    {
        $data = $request->validate([
            'customer_name' => 'sometimes|string|max:255',
            'customer_type' => 'sometimes|in:debtor,route',
            'phone_number' => 'nullable|string|max:45',
            'additional_phone' => 'nullable|string|max:45',
            'email' => 'nullable|email|max:255',
            'town' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'route_id' => 'nullable|integer|min:1',
            'kra_pin' => 'nullable|string|max:45',
            'terms_of_payment' => 'nullable|string|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
            'branch_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->mobileCustomers->update($request->user(), $customerNum, $data),
        );
    }

    /** POST /mobile/device-tokens — register FCM/APNs token for push. */
    public function registerDeviceToken(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        $record = $this->deviceTokens->register(
            $request->user(),
            $data['token'],
            UserDeviceToken::CHANNEL_MOBILE_SALES,
            $data['platform'] ?? null,
        );

        return response()->json([
            'message' => 'Device token registered.',
            'id' => $record->id,
        ]);
    }

    /** DELETE /mobile/device-tokens — unregister on sign-out. */
    public function unregisterDeviceToken(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $this->deviceTokens->unregister(
            $request->user(),
            $data['token'],
            UserDeviceToken::CHANNEL_MOBILE_SALES,
        );

        return response()->json(['message' => 'Device token removed.']);
    }
}
