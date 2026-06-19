<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Auth\UserMobileOrderScopeService;
use App\Services\Customers\MobileCustomerService;
use App\Services\Sales\MobileSalesService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MobileSalesController extends Controller
{
    public function __construct(
        protected MobileSalesService $mobileSales,
        protected MobileCustomerService $mobileCustomers,
        protected UserMobileOrderScopeService $mobileScope,
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

    /** GET /mobile/orders — paginated mobile orders for the signed-in rep. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:200',
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

    /** POST /mobile/orders/{saleId}/returns — line or full-order return with stock restore. */
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

    /** GET /mobile/routes — active routes available to the signed-in mobile rep. */
    public function indexRoutes(Request $request)
    {
        $routes = $this->mobileScope->listRoutesForUser($request->user());

        return response()->json([
            'data' => $routes->map(static fn ($route) => [
                'id' => (int) $route->id,
                'route_name' => $route->route_name,
                'route_markup_price' => $route->route_markup_price,
            ])->values(),
            'route_selection_locked' => $this->mobileScope->isRouteSelectionLocked($request->user()),
            'assigned_route_id' => $request->user()->assigned_route_id
                ? (int) $request->user()->assigned_route_id
                : null,
        ]);
    }

    /** GET /mobile/customers — ERP customers for the signed-in rep. */
    public function indexCustomers(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:200',
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
}
