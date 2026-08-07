<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartAccess;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartPayments;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesMpesaPayments;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesPricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AddCartLineRequest;
use App\Http\Requests\Sales\StoreCartRequest;
use App\Http\Requests\Sales\UpdateCartLineRequest;
use App\Models\CartLine;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Kra\SalesVatCalculator;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Sales\MobileRouteMarkupCheckoutService;
use App\Services\Sales\OrderCancellationRequestService;
use App\Services\Sales\OrderSourceResolver;
use App\Services\Sales\SaleCancellationService;
use App\Services\Sales\OrderNumberAllocator;
use App\Services\Sales\PosLinePricingService;
use App\Support\SalesCheckoutSettings;
use App\Services\Sales\PosOrderEditService;
use App\Services\Auth\UserLoginChannelService;
use App\Support\TenantRouteRules;
use App\Services\Auth\UserMobileOrderScopeService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Inventory\BranchStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Illuminate\Support\Str;

class CartOperationsController extends Controller
{
    use HandlesCartAccess;
    use HandlesCartPayments;
    use HandlesInventory;
    use HandlesMpesaPayments;
    use HandlesPricing;

    public function __construct(protected ErpContext $erp) {}

    protected function cartResponse(
        TemporaryCart $cart,
        User $user,
        int $status = 200,
        array $extra = [],
        bool $includeNextOrderNum = false,
    ) {
        return response()->json(
            $this->presentCart($cart, $user, $extra, $includeNextOrderNum),
            $status,
        );
    }

    public function store(StoreCartRequest $request)
    {
        $cart = $this->getOrCreateCart($request->user(), $request->validated());
        $cart->load('lines');

        return $this->cartResponse($cart, $request->user(), 201, includeNextOrderNum: true);
    }

    public function show(int|string $cartId)
    {
        $user = request()->user();

        // Mobile warm-start and cart polls do not need next_order_num. Peeking
        // scans sales order_num ceilings and was a common mobile timeout amplifier.
        // Create / checkout paths still request the peek when needed.
        return $this->cartResponse($this->findOwnedCart($cartId, $user), $user, includeNextOrderNum: false);
    }

    public function update(\Illuminate\Http\Request $request, int|string $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $salesSettings = $gate->moduleSettings('sales');
        $orgId = (int) ($this->userAccess()->organizationId($request->user(), $request) ?? 0);
        $data = $request->validate([
            'route_id' => TenantRouteRules::nullable($orgId ?: null),
            'order_discount' => 'sometimes|numeric|min:0',
        ]);

        $updates = [];
        if (array_key_exists('route_id', $data)) {
            $routeId = app(UserMobileOrderScopeService::class)->resolveCartRouteId(
                $request->user(),
                $data['route_id'] ?? null,
            );
            app(UserMobileOrderScopeService::class)->assertCartRouteId($request->user(), $routeId);
            $updates['route_id'] = $routeId;
        }
        if (array_key_exists('order_discount', $data)) {
            $orderDiscount = max(0, (float) $data['order_discount']);
            $discountService = app(\App\Services\Sales\DiscountApprovalService::class);
            if ($discountService->allowsOrderDiscount($salesSettings, $request->user())) {
                $discountService->assertDirectManualDiscountAllowed(
                    $request->user(),
                    $salesSettings,
                    $orderDiscount,
                    'order_discount',
                );
                $updates['order_discount'] = $orderDiscount;
            } else {
                $updates['order_discount'] = 0;
            }
        }

        if ($updates !== []) {
            $cart->update($updates);
            $cart->increment('update_no');
        }

        return $this->cartResponse($cart->fresh('lines'), $request->user(), includeNextOrderNum: false);
    }

    public function requestDiscount(Request $request, int|string $cartId)
    {
        $user = $request->user();
        $cart = $this->findOwnedCart($cartId, $user);
        $gate = $this->erp->gateForUser($user);

        $data = $request->validate([
            'scope' => 'required|in:line,order',
            'line_ref' => 'nullable|string',
            'discount_amount' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
            'defer_approval' => 'nullable|boolean',
        ]);

        $result = app(\App\Services\Sales\DiscountApprovalService::class)->applyOrRequest(
            $user,
            $cart,
            $gate,
            $data,
        );

        $payload = [
            'applied' => $result['applied'],
            'cart' => $this->presentCart($result['cart'], $user, includeNextOrderNum: false),
        ];

        if (! empty($result['deferred_approval'])) {
            $payload['deferred_approval'] = true;
        }

        if (! $result['applied']) {
            $payload['pending_approval'] = true;
            $payload['action_request_id'] = (int) $result['action_request']->id;
        }

        return response()->json($payload, $result['applied'] ? 200 : 202);
    }

    public function addLine(AddCartLineRequest $request, int|string $cartId)
    {
        $user = $request->user();
        $cart = $this->findOwnedCart($cartId, $user, withLines: false);
        $gate = $this->erp->gateForUser($user);
        $this->addCartLine($cart, $request->validated(), $user, $gate);

        return $this->cartResponse($cart->fresh('lines'), $user, 201, includeNextOrderNum: false);
    }

    public function updateLine(UpdateCartLineRequest $request, int|string $cartId, string $lineRef)
    {
        $user = $request->user();
        $cart = $this->findOwnedCart($cartId, $user);
        $gate = $this->erp->gateForUser($user);
        $this->updateCartLine($cart, $lineRef, $request->validated(), $user, $gate);

        return $this->cartResponse($cart->fresh('lines'), $user, includeNextOrderNum: false);
    }

    /** POST /sales/carts/{cartId}/apply-advised-discounts — apply manager-advised per-line discounts in one request. */
    public function applyAdvisedDiscounts(Request $request, int|string $cartId)
    {
        $user = $request->user();
        $cart = $this->findOwnedCart($cartId, $user);
        $gate = $this->erp->gateForUser($user);
        $discounts = app(\App\Services\Sales\DiscountApprovalService::class);

        $data = $request->validate([
            'update_no' => 'nullable|integer|min:0',
        ]);

        if (
            array_key_exists('update_no', $data)
            && (int) $data['update_no'] !== (int) $cart->update_no
        ) {
            throw new InvalidArgumentException('Cart was updated elsewhere. Refresh and try again.');
        }

        if (! $discounts->cartResubmitsRejectedDiscountOrder($cart)) {
            throw new InvalidArgumentException('Cart is not a discount-rejected order edit.');
        }

        $supersededId = (int) ($cart->superseded_sale_id ?? 0);
        if ($supersededId <= 0) {
            throw new InvalidArgumentException('No superseded order on this cart.');
        }

        $superseded = Sale::query()->findOrFail($supersededId);
        $advisedLines = $discounts->saleAdvisedDiscountLines($superseded);
        if ($advisedLines === []) {
            throw new InvalidArgumentException('No per-line advised discounts on this order.');
        }

        $advisedByCode = collect($advisedLines)->keyBy(
            static fn (array $line) => (string) ($line['product_code'] ?? ''),
        );
        $display = app(\App\Services\Sales\SaleLineQuantityDisplayService::class);

        foreach ($cart->lines as $line) {
            $code = (string) $line->product_code;
            $advised = $advisedByCode->get($code);
            if ($advised === null) {
                continue;
            }

            $product = $this->findProductForCart($cart, $code, $user);
            $packQty = max(
                0.0001,
                $display->entryQtyFromBase(
                    (float) $line->quantity,
                    $product,
                    (bool) $line->on_wholesale_retail,
                ),
            );
            $discountGiven = round((float) ($advised['advised_discount'] ?? 0) * $packQty, 2);

            $this->updateCartLine($cart, (string) $line->update_code, [
                'quantity' => (float) $line->quantity,
                'on_wholesale_retail' => (bool) $line->on_wholesale_retail,
                'discount_given' => $discountGiven,
            ], $user, $gate);

            $cart->refresh();
            $cart->load('lines');
        }

        return $this->cartResponse($cart->fresh('lines'), $user, includeNextOrderNum: false);
    }

    public function deleteLine(int|string $cartId, string $lineRef)
    {
        $user = request()->user();
        $cart = $this->findOwnedCart($cartId, $user);
        $this->removeCartLine($cart, $lineRef);

        return $this->cartResponse($cart->fresh('lines'), $user, includeNextOrderNum: false);
    }

    /**
     * PUT /sales/carts/{cartId}/lines — replace all cart lines in one request.
     * Used by previous-order edit flush so F10 does not N× POST each line.
     * Preserves held_order_num / superseded_sale_id (unlike DELETE /lines clear).
     */
    public function replaceLines(Request $request, int|string $cartId)
    {
        $user = $request->user();
        $cart = $this->findOwnedCart($cartId, $user);
        $gate = $this->erp->gateForUser($user);

        // Previous-order edits may clear every line (empty revision → cancel + return on checkout).
        // Use present|array (not required) so lines:[] is accepted — Laravel treats [] as empty.
        $allowEmptyLines = (int) ($cart->superseded_sale_id ?? 0) > 0;
        $data = $request->validate([
            'lines' => $allowEmptyLines ? 'present|array' : 'required|array|min:1',
            'lines.*.product_code' => 'required|string|max:64',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.display_unit_price' => 'nullable|numeric|min:0',
            'lines.*.uom' => 'nullable|string|max:64',
            'lines.*.product_vat' => 'nullable|numeric|min:0',
            'lines.*.discount_given' => 'nullable|numeric|min:0',
            'lines.*.on_wholesale_retail' => 'nullable|boolean',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'order_discount' => 'nullable|numeric|min:0',
            'update_no' => 'nullable|integer|min:0',
        ]);

        if (
            array_key_exists('update_no', $data)
            && (int) $data['update_no'] !== (int) $cart->update_no
        ) {
            throw new InvalidArgumentException('Cart was updated elsewhere. Refresh and try again.');
        }

        $heldOrderNum = $cart->held_order_num;
        $supersededSaleId = $cart->superseded_sale_id;
        $lines = array_values(array_filter(
            $data['lines'] ?? [],
            static fn ($row) => is_array($row),
        ));

        // Retry on MySQL deadlock while releasing/re-reserving many SKUs at once.
        $cart = DB::transaction(function () use ($cart, $data, $user, $gate, $heldOrderNum, $supersededSaleId, $lines) {
            $this->releaseCartReservations((int) $cart->id);
            CartLine::where('cart_id', $cart->id)->delete();

            $updates = [];
            if (array_key_exists('order_discount', $data)) {
                $updates['order_discount'] = max(0, (float) $data['order_discount']);
            }
            // Keep previous-order edit markers intact.
            $updates['held_order_num'] = $heldOrderNum;
            $updates['superseded_sale_id'] = $supersededSaleId;
            $cart->update($updates);
            $cart->refresh();

            if ($lines !== []) {
                $this->addDraftLinesToCart($cart, $lines, $user, $gate);
            }

            return $cart->fresh('lines');
        }, 5);

        return $this->cartResponse($cart, $user, includeNextOrderNum: false);
    }

    public function clear(int|string $cartId)
    {
        $user = request()->user();
        $resolvedId = $this->resolveCartId($cartId);

        // POS fire-and-forget DELETE after checkout often races the cart already being gone.
        $cart = TemporaryCart::query()->find($resolvedId);
        if (! $cart) {
            return response()->json(['ok' => true, 'already_cleared' => true]);
        }
        if ((int) $cart->user_id !== (int) $user->id) {
            abort(403, 'This cart belongs to another cashier.');
        }
        $this->userAccess()->assertBranchAccess(
            $user,
            $cart->branch_id ? (int) $cart->branch_id : null,
            'This cart belongs to another branch.',
        );

        // Serialize against checkout / line edits. Retry MySQL 1213 deadlocks (stock
        // reservations vs cart_lines lock order under concurrent POS traffic).
        DB::transaction(function () use ($resolvedId, $user) {
            $locked = TemporaryCart::query()->whereKey($resolvedId)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }
            $this->clearCart($locked, $user);
        }, 5);

        return response()->json(['ok' => true]);
    }

    public function restoreHeldOrder(Request $request, int $saleId)
    {
        $user = $request->user();
        $sale = $this->findScopedSale($saleId, $user)->load([
            'items',
            'customer:customer_num,customer_name,organization_id',
        ]);

        $this->assertSaleRestorableToCart($sale, $user);

        $gate = $this->erp->gateForUser($user);
        $channel = $this->resolveCartChannel($sale->channel ?: 'pos', $gate, [
            'order_source' => $sale->order_source ?? 'backoffice',
        ], $user->currentAccessToken());

        $cart = $this->getOrCreateCart($user, [
            'channel' => $channel,
            'order_source' => $sale->order_source ?? $channel,
            'branch_id' => $sale->branch_id ?? $user->branch_id,
            'route_id' => $sale->route_id,
        ]);

        // Resume an in-progress edit of the same sale — skip KRA void + stock reverse.
        if (
            (int) ($cart->superseded_sale_id ?? 0) === (int) $sale->id
            && (int) ($cart->held_order_num ?? 0) === (int) $sale->order_num
            && $cart->lines()->exists()
        ) {
            return $this->cartResponse(
                $cart->loadMissing('lines'),
                $user,
                extra: ['restored_from_sale' => $this->restoredFromSalePayload($sale)],
            );
        }

        if ($cart->lines()->exists() && ! $request->boolean('replace')) {
            throw new InvalidArgumentException(
                'Your cart already has items. Clear it first or confirm replace.',
            );
        }

        // Parked held/draft sales are unfinished — restore as a normal new cart, not a
        // previous-order edit (no held_order_num / superseded_sale_id).
        if (in_array((string) $sale->status, ['held', 'draft'], true)) {
            $cart = $this->restoreParkedSaleToNewCart($cart, $sale, $user, $gate);

            return $this->cartResponse(
                $cart,
                $user,
                extra: ['restored_from_sale' => $this->restoredFromSalePayload($sale)],
                includeNextOrderNum: true,
            );
        }

        // Fiscal void before the cart transaction so device/KRA latency does not hold DB locks.
        app(PosOrderEditService::class)->fiscalVoidBeforeEdit($sale, $user, $gate);

        $cart = DB::transaction(function () use ($cart, $sale, $user, $gate) {
            if ($cart->lines()->exists()) {
                $this->clearCart($cart, $user);
            }

            $hadReservations = ! $sale->stock_balanced
                && $this->saleHasActiveReservations((int) $sale->id);

            if ($sale->stock_balanced) {
                $this->reverseSaleStockDeductions($sale, $user);
            } elseif (! $hadReservations) {
                $this->releaseSaleReservations((int) $sale->id);
            }

            $heldOrderNum = (int) $sale->order_num;

            $cart->update([
                'route_id' => $sale->route_id,
                'order_discount' => (float) ($sale->order_discount ?? 0),
                'held_order_num' => $heldOrderNum,
                'superseded_sale_id' => (int) $sale->id,
            ]);

            $this->addRestoredSaleItemsToCart(
                $cart->fresh(),
                $sale,
                $user,
                $gate,
                skipStockReserve: $hadReservations,
            );

            if ($hadReservations) {
                $this->transferSaleReservationsToCart((int) $sale->id, (int) $cart->id);
                $this->bindCartReservationsToLines($cart->fresh('lines'), $user, $gate);
            }

            $meta = array_merge($sale->fulfillment_meta ?? [], [
                'pos_editing_in_progress' => true,
                'pos_editing_at' => now()->toIso8601String(),
                'pos_editing_by' => (int) $user->id,
                'original_order_num' => $heldOrderNum,
                'original_status' => (string) $sale->status,
                'original_stock_balanced' => (bool) $sale->stock_balanced,
            ]);

            // Keep the sale visible in Sales & Orders / X/Z until checkout commits the
            // revision. Cancelling here made the order vanish for the whole edit and
            // wrecked till maths (ORDTTL dropped then jumped). Checkout frees the
            // order_num via resolveCheckoutOrderNum when superseded_sale_id matches.
            $sale->update([
                'stock_balanced' => 0,
                'fulfillment_meta' => $meta,
            ]);

            return $cart->fresh('lines');
        });

        return $this->cartResponse(
            $cart,
            $user,
            extra: ['restored_from_sale' => $this->restoredFromSalePayload($sale)],
        );
    }

    /**
     * Lightweight sale metadata so POS can paint customer / ticket without a second GET /sales/{id}.
     *
     * @return array<string, mixed>
     */
    protected function restoredFromSalePayload(Sale $sale): array
    {
        $customerName = trim((string) ($sale->customer_name_override ?? ''));
        if ($customerName === '') {
            $customerName = trim((string) ($sale->customer?->customer_name ?? ''));
        }
        if ($customerName === '' && method_exists($sale, 'customerDisplayName')) {
            $customerName = trim($sale->customerDisplayName());
        }

        return [
            'id' => (int) $sale->id,
            'order_num' => $sale->order_num !== null ? (int) $sale->order_num : null,
            'pos_order_num' => $sale->pos_order_num !== null ? (int) $sale->pos_order_num : null,
            'pos_order_date' => $sale->pos_order_date
                ? (string) $sale->pos_order_date
                : null,
            'status' => (string) ($sale->status ?? ''),
            'customer_num' => $sale->customer_num !== null ? (int) $sale->customer_num : null,
            'customer_name_override' => $customerName !== '' ? $customerName : null,
            'customer_display_name' => $customerName !== '' ? $customerName : null,
            'payment_method_code' => $sale->payment_method_code
                ? strtoupper((string) $sale->payment_method_code)
                : null,
            'order_total' => round((float) ($sale->order_total ?? 0), 2),
            'amount_paid' => round((float) ($sale->amount_paid ?? 0), 2),
            'cash' => round((float) ($sale->cash ?? 0), 2),
            'mpesa_amount' => round((float) ($sale->mpesa_amount ?? 0), 2),
            'equity_amount' => round((float) ($sale->equity_amount ?? 0), 2),
            'kcb_amount' => round((float) ($sale->kcb_amount ?? 0), 2),
        ];
    }

    /**
     * Resume a parked (held/draft) sale as a normal POS cart so Complete books a new sale.
     * Does not enter previous-order edit mode (held_order_num / superseded_sale_id).
     */
    protected function restoreParkedSaleToNewCart(
        TemporaryCart $cart,
        Sale $sale,
        User $user,
        CapabilityGate $gate,
    ): TemporaryCart {
        return DB::transaction(function () use ($cart, $sale, $user, $gate) {
            if ($cart->lines()->exists()) {
                $this->clearCart($cart, $user);
            }

            $hadReservations = ! $sale->stock_balanced
                && $this->saleHasActiveReservations((int) $sale->id);

            // Held sales are never fiscalized / stock-balanced; keep or move reservations only.
            if ($sale->stock_balanced) {
                $this->reverseSaleStockDeductions($sale, $user);
            } elseif (! $hadReservations) {
                $this->releaseSaleReservations((int) $sale->id);
            }

            $cart->update([
                'route_id' => $sale->route_id,
                'order_discount' => (float) ($sale->order_discount ?? 0),
                'held_order_num' => null,
                'superseded_sale_id' => null,
            ]);
            $cart->refresh();

            $this->addRestoredSaleItemsToCart(
                $cart,
                $sale,
                $user,
                $gate,
                skipStockReserve: $hadReservations,
            );

            if ($hadReservations) {
                $this->transferSaleReservationsToCart((int) $sale->id, (int) $cart->id);
                $this->bindCartReservationsToLines($cart->fresh('lines'), $user, $gate);
            }

            $meta = array_merge($sale->fulfillment_meta ?? [], [
                'restored_held_to_cart' => true,
                'restored_held_at' => now()->toIso8601String(),
                'restored_from_order_num' => (int) $sale->order_num,
            ]);

            $sale->update([
                // Free the live order number so a later checkout cannot collide on uq_org_order_num.
                'order_num' => app(OrderNumberAllocator::class)->tombstoneForSupersededSale((int) $sale->id),
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'stock_balanced' => 0,
                'archived' => 1,
                'fulfillment_meta' => $meta,
            ]);

            $this->reverseSaleJournalIfPosted($sale, $user);
            app(CustomerInvoiceService::class)->voidForCancelledSale($sale->fresh(), $user);

            app(\App\Services\Notifications\ActionRequestService::class)->cancelAllPendingForSale(
                $sale->fresh(),
                $user,
                'Held order restored to cart.',
            );

            return $cart->fresh('lines');
        });
    }

    /** GET /sales/customers/lookup — search registered customers for POS credit checkout */
    public function lookupCustomers(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'customer_num' => 'nullable|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $orgId = (int) $request->user()->organization_id;
        $query = Customer::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at');

        if (! empty($data['customer_num'])) {
            $query->where('customer_num', (int) $data['customer_num']);
        } else {
            $term = trim((string) ($data['q'] ?? ''));
            if ($term !== '') {
                $like = '%'.$term.'%';
                $query->where(function ($builder) use ($like, $term) {
                    $builder
                        ->where('customer_name', 'like', $like)
                        ->orWhere('phone_number', 'like', $like)
                        ->orWhere('additional_phone', 'like', $like)
                        ->orWhere('kra_pin', 'like', $like);
                    if (ctype_digit($term)) {
                        $builder->orWhere('customer_num', 'like', $like);
                    }
                });
            }
        }

        $perPage = min((int) ($data['per_page'] ?? 20), 50);
        $rows = $query
            ->orderBy('customer_name')
            ->limit($perPage)
            ->get([
                'customer_num',
                'customer_name',
                'phone_number',
                'additional_phone',
                'kra_pin',
                'credit_limit',
                'current_balance',
                'customer_status',
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /sales/orders/same-day-for-customer
     * When org setting append_same_day_customer_orders is on, returns today's open
     * mobile order for the customer (same branch) so the field app can continue it.
     */
    public function sameDayForCustomer(Request $request)
    {
        $data = $request->validate([
            'customer_num' => 'required|integer|min:1',
            'channel' => 'sometimes|nullable|string|in:mobile',
            'branch_id' => 'sometimes|nullable|integer|min:1',
        ]);

        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $salesSettings = $gate->moduleSettings('sales');
        $service = app(\App\Services\Sales\SameDayCustomerOrderService::class);

        if (! $service->enabled($salesSettings)) {
            return response()->json(['sale' => null, 'enabled' => false]);
        }

        $branchId = isset($data['branch_id'])
            ? (int) $data['branch_id']
            : ($user->branch_id ? (int) $user->branch_id : null);

        $sale = $service->findOpenOrderToday(
            $user,
            (int) $data['customer_num'],
            $branchId,
            'mobile',
        );

        if (! $sale) {
            return response()->json(['sale' => null, 'enabled' => true]);
        }

        return response()->json([
            'enabled' => true,
            'sale' => [
                'id' => (int) $sale->id,
                'order_num' => (int) $sale->order_num,
                'pos_order_num' => $sale->pos_order_num != null ? (int) $sale->pos_order_num : null,
                'status' => $sale->status,
                'payment_status' => $sale->payment_status,
                'order_total' => round((float) $sale->order_total, 2),
                'amount_paid' => round((float) $sale->amount_paid, 2),
                'customer_num' => (int) $sale->customer_num,
                'item_count' => $sale->items()->count(),
            ],
        ]);
    }

    public function lookupLoyaltyCard(Request $request)
    {
        $gate = $this->erp->gateForUser($request->user());
        $salesSettings = $gate->moduleSettings('sales');
        if (empty($salesSettings['enable_redeemable_points'])) {
            throw new InvalidArgumentException('Redeemable points are not enabled.');
        }

        $data = $request->validate(['phone' => 'required|string|max:45']);
        $card = $this->findLoyaltyCardByPhone((int) $request->user()->organization_id, $data['phone'], false);
        $this->syncCustomerPhoneOnCard($card);
        $rate = max(0, (float) ($salesSettings['point_cash_value'] ?? 1));
        $earnPerKes = max(0, (float) ($salesSettings['points_earn_per_kes'] ?? 1000));

        return response()->json([
            'loyalty_card_id' => $card->id,
            'card_number' => $card->card_number,
            'customer_num' => $card->customer_num,
            'customer_name' => $card->customer?->customer_name,
            'phone_number' => $card->phone_number,
            'points_balance' => (float) $card->points_balance,
            'point_cash_value' => $rate,
            'points_earn_per_kes' => $earnPerKes,
            'max_cash_value' => round((float) $card->points_balance * $rate, 2),
        ]);
    }

    public function attachLoyaltyCard(Request $request, int|string $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $salesSettings = $gate->moduleSettings('sales');
        if (empty($salesSettings['enable_redeemable_points'])) {
            throw new InvalidArgumentException('Redeemable points are not enabled.');
        }

        $data = $request->validate(['phone' => 'required|string|max:45']);
        $card = $this->findLoyaltyCardByPhone((int) $request->user()->organization_id, $data['phone'], false);
        $this->syncCustomerPhoneOnCard($card);

        $cart->update(['loyalty_card_id' => $card->id]);
        $cart->increment('update_no');

        return response()->json([
            'cart' => $this->presentCart($cart->fresh('lines'), $request->user(), includeNextOrderNum: false),
            'loyalty' => [
                'loyalty_card_id' => $card->id,
                'card_number' => $card->card_number,
                'customer_num' => $card->customer_num,
                'customer_name' => $card->customer?->customer_name,
                'points_balance' => (float) $card->points_balance,
            ],
        ]);
    }

    public function applyVoucherPayment(Request $request, int|string $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $salesSettings = $gate->moduleSettings('sales');
        if (empty($salesSettings['enable_vouchers'])) {
            throw new InvalidArgumentException('Vouchers are not enabled.');
        }

        $data = $request->validate([
            'voucher_code' => 'required|string|max:50',
            'amount' => 'nullable|numeric|min:0',
        ]);

        $code = $data['voucher_code'];
        $orgId = (int) $request->user()->organization_id;
        $voucher = Voucher::where('organization_id', $orgId)
            ->where('voucher_code', strtoupper(trim($code)))
            ->first();

        if (! $voucher) {
            throw new InvalidArgumentException('Voucher not found.');
        }

        if ($voucher->voucher_kind === 'discount') {
            $discountVoucher = $this->findDiscountVoucher($orgId, $code);
            $lineNet = (float) CartLine::where('cart_id', $cart->id)->sum('amount');
            $discount = $this->computeVoucherDiscountAmount($discountVoucher, $lineNet);

            if (method_exists($this, 'releaseCartMpesaPayments')) {
                $this->releaseCartMpesaPayments($cart);
            }

            $cart->update([
                'discount_voucher_id' => $discountVoucher->id,
                'order_discount' => $discount,
                'payment_voucher_id' => null,
                'voucher_payment_amount' => 0,
                'loyalty_card_id' => null,
                'points_redeemed' => 0,
                'points_payment_amount' => 0,
                'mpesa_phone' => null,
                'mpesa_payment_amount' => 0,
                'mpesa_transaction_code' => null,
            ]);
            $cart->increment('update_no');
            $fresh = $cart->fresh('lines');

            return response()->json([
                'cart' => $this->presentCart($fresh, $request->user()),
                'voucher' => [
                    'id' => $discountVoucher->id,
                    'voucher_code' => $discountVoucher->voucher_code,
                    'voucher_kind' => 'discount',
                    'discount_type' => $discountVoucher->discount_type,
                    'applied_amount' => $discount,
                    'amount_due' => $this->cartAmountDue($fresh),
                ],
            ]);
        }

        $voucher = $this->findPaymentVoucher($orgId, $code);
        $orderTotal = $this->cartOrderTotal($cart);
        $otherPoints = max(0, (float) ($cart->points_payment_amount ?? 0));
        $maxApplicable = min((float) $voucher->balance, max(0, $orderTotal - $otherPoints));
        $amount = array_key_exists('amount', $data) && $data['amount'] !== null
            ? min((float) $data['amount'], $maxApplicable)
            : $maxApplicable;

        $cart->update([
            'payment_voucher_id' => $voucher->id,
            'discount_voucher_id' => null,
            'order_discount' => 0,
            'voucher_payment_amount' => $amount,
        ]);
        $cart->increment('update_no');
        $fresh = $cart->fresh('lines');

        return response()->json([
            'cart' => $this->presentCart($fresh, $request->user()),
            'voucher' => [
                'id' => $voucher->id,
                'voucher_code' => $voucher->voucher_code,
                'voucher_kind' => 'payment',
                'balance' => (float) $voucher->balance,
                'applied_amount' => $amount,
                'amount_due' => $this->cartAmountDue($fresh),
            ],
        ]);
    }

    public function applyPointsPayment(Request $request, int|string $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $salesSettings = $gate->moduleSettings('sales');
        if (empty($salesSettings['enable_redeemable_points'])) {
            throw new InvalidArgumentException('Redeemable points are not enabled.');
        }

        $data = $request->validate([
            'phone' => 'required|string|max:45',
            'points' => 'nullable|numeric|min:0',
        ]);

        $card = $this->findLoyaltyCardByPhone((int) $request->user()->organization_id, $data['phone']);
        $orderTotal = $this->cartOrderTotal($cart);
        $otherVoucher = max(0, (float) ($cart->voucher_payment_amount ?? 0));
        $remaining = max(0, $orderTotal - $otherVoucher);
        $maxPoints = min((float) $card->points_balance, $remaining / max(0.0001, (float) ($salesSettings['point_cash_value'] ?? 1)));
        $points = array_key_exists('points', $data) && $data['points'] !== null
            ? min((float) $data['points'], $maxPoints)
            : $maxPoints;
        $cashValue = $this->pointsCashValue($salesSettings, $points);

        $cart->update([
            'loyalty_card_id' => $card->id,
            'points_redeemed' => $points,
            'points_payment_amount' => $cashValue,
        ]);
        $cart->increment('update_no');
        $fresh = $cart->fresh('lines');

        return response()->json([
            'cart' => $this->presentCart($fresh, $request->user()),
            'loyalty' => [
                'loyalty_card_id' => $card->id,
                'card_number' => $card->card_number,
                'customer_name' => $card->customer?->customer_name,
                'points_balance' => (float) $card->points_balance,
                'points_redeemed' => $points,
                'applied_amount' => $cashValue,
                'amount_due' => $this->cartAmountDue($fresh),
            ],
        ]);
    }

    public function updateCartPaymentExtras(Request $request, int|string $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $data = $request->validate([
            'mpesa_phone' => 'nullable|string|max:45',
        ]);

        if (array_key_exists('mpesa_phone', $data)) {
            $cart->update(['mpesa_phone' => $data['mpesa_phone'] ?: null]);
            $cart->increment('update_no');
        }

        return $this->cartResponse($cart->fresh('lines'), $request->user());
    }

    public function clearCartPayments(int|string $cartId)
    {
        $cart = $this->findOwnedCart($cartId, request()->user());
        $this->clearCartPaymentOptions($cart);
        $cart->increment('update_no');

        return $this->cartResponse($cart->fresh('lines'), request()->user());
    }

    public function cancelHeldOrder(Request $request, int $saleId)
    {
        $user = $request->user();
        $sale = $this->findScopedSale($saleId, $user);

        if ($sale->status !== 'held') {
            throw new InvalidArgumentException('Only held orders can be deleted.');
        }

        if ((int) $sale->cashier_id !== (int) $user->id
            && ! app(\App\Services\Auth\UserPermissionService::class)->canEditOthersSalesOrders(
                $user,
                $this->erp->gateForUser($user),
            )) {
            throw new InvalidArgumentException('You can only cancel your own held orders.');
        }

        $deletedId = (int) $sale->id;
        $orderNum = (int) ($sale->order_num ?? 0);

        DB::transaction(function () use ($sale, $user, $deletedId) {
            $this->restoreCancelledSaleStock($sale, $user);

            $this->reverseSaleJournalIfPosted($sale, $user);
            app(CustomerInvoiceService::class)->voidForCancelledSale($sale->fresh(), $user);

            app(\App\Services\Notifications\ActionRequestService::class)->cancelAllPendingForSale(
                $sale->fresh(),
                $user,
                'Held order was deleted.',
            );

            // Detach / remove dependents that do not cascade, then delete the park completely
            // so it never appears under Cancelled orders.
            TemporaryCart::query()
                ->where('superseded_sale_id', $deletedId)
                ->update(['superseded_sale_id' => null]);

            if (Schema::hasTable('mpesa_incoming_payments')
                && Schema::hasColumn('mpesa_incoming_payments', 'applied_sale_id')) {
                DB::table('mpesa_incoming_payments')
                    ->where('applied_sale_id', $deletedId)
                    ->update(['applied_sale_id' => null]);
            }

            if (Schema::hasTable('kra_responses')) {
                DB::table('kra_responses')->where('sale_id', $deletedId)->delete();
            }
            if (Schema::hasTable('credit_notes')) {
                DB::table('credit_notes')->where('sale_id', $deletedId)->update(['sale_id' => null]);
            }
            if (Schema::hasTable('customer_returns')) {
                DB::table('customer_returns')->where('sale_id', $deletedId)->update(['sale_id' => null]);
            }
            if (Schema::hasTable('returns')) {
                DB::table('returns')->where('sale_id', $deletedId)->update(['sale_id' => null]);
            }

            if (Schema::hasTable('customer_invoices')) {
                $invoiceIds = DB::table('customer_invoices')
                    ->where('sale_id', $deletedId)
                    ->pluck('id');
                if ($invoiceIds->isNotEmpty()) {
                    if (Schema::hasTable('customer_invoice_payments')) {
                        DB::table('customer_invoice_payments')
                            ->whereIn('customer_invoice_id', $invoiceIds)
                            ->delete();
                    }
                    DB::table('customer_invoices')->whereIn('id', $invoiceIds)->delete();
                }
            }

            $sale->delete();
        });

        return response()->json([
            'deleted' => true,
            'id' => $deletedId,
            'order_no' => $orderNum,
        ]);
    }

    /** POST /sales/orders/{saleId}/cancel — cancel a mobile (or editable) order and restore stock. */
    public function cancelOrder(Request $request, int $saleId)
    {
        $user = $request->user();
        $sale = $this->findScopedSale($saleId, $user)->load('items');

        if (app(PosOrderEditService::class)->blocksPreviousDayMobileMutation($sale)) {
            throw new InvalidArgumentException(
                'Mobile orders from previous dates cannot be edited, cancelled, or returned.',
            );
        }

        $gate = $this->erp->gateForUser($user);
        $cancellations = app(SaleCancellationService::class);
        $cancellationRequests = app(OrderCancellationRequestService::class);

        if (
            $cancellations->cancellationApprovalEnabled($gate)
            && ! $cancellationRequests->canDirectCancel($user)
        ) {
            throw new InvalidArgumentException(
                'Order cancellation requires manager approval. Submit a cancellation request instead.',
            );
        }

        app(SaleCancellationService::class)->cancelSale($sale, $user, $gate);

        $sale = $sale->fresh();

        return response()->json([
            'cancelled' => true,
            'id' => (int) $sale->id,
            'order_no' => (int) $sale->order_num,
            'status' => 'cancelled',
        ]);
    }

    protected function reverseSaleJournalIfPosted(Sale $sale, User $user): void
    {
        app(ReferenceJournalReversalService::class)->reverseIfEnabled(
            'sale',
            (int) $sale->id,
            $user,
            $this->erp->gateForUser($user),
        );
    }

    protected function assertSaleRestorableToCart(Sale $sale, User $user): void
    {
        if (app(PosOrderEditService::class)->blocksPreviousDayMobileMutation($sale)) {
            throw new InvalidArgumentException(
                'Mobile orders from previous dates cannot be edited, cancelled, or returned.',
            );
        }

        app(PosOrderEditService::class)->assertSaleEditable(
            $sale,
            $user,
            $this->erp->gateForUser($user),
        );
    }

    /**
     * Map legacy pos-channel requests to backend when external POS is disabled.
     *
     * @param  array<string, mixed>  $input
     */
    protected function resolveCartChannel(
        string $channel,
        CapabilityGate $gate,
        array $input = [],
        $token = null,
    ): string {
        if ($gate->channelEnabled($channel)) {
            return $channel;
        }

        if ($channel !== 'pos' || ! $gate->enabled('sales.backend')) {
            throw new InvalidArgumentException("Channel [{$channel}] is not enabled for this organization.");
        }

        $orderSource = strtolower((string) ($input['order_source'] ?? ''));
        $loginChannel = strtolower((string) ($token?->login_channel ?? ''));

        if (
            in_array($orderSource, ['backoffice', 'backend'], true)
            || $loginChannel === UserLoginChannelService::BACKOFFICE
        ) {
            return 'backend';
        }

        throw new InvalidArgumentException("Channel [{$channel}] is not enabled for this organization.");
    }

    protected function getOrCreateCart(User $user, array $input): TemporaryCart
    {
        $gate = $this->erp->gateForUser($user);
        $token = $user->currentAccessToken();
        $channel = $this->resolveCartChannel((string) ($input['channel'] ?? 'pos'), $gate, $input, $token);
        $orderSource = app(\App\Services\Sales\OrderSourceResolver::class)->defaultForCart($input, $token);

        $branchId = $this->userAccess()->resolveBranchId($user, $input['branch_id'] ?? null);
        $mobileScope = app(UserMobileOrderScopeService::class);
        $routeId = $mobileScope->resolveCartRouteId(
            $user,
            $input['route_id'] ?? null,
        );
        $scope = $mobileScope->scope($user);
        $assignedRouteIds = $mobileScope->assignedRouteIds($user);
        if ($channel === 'mobile' && $scope === UserMobileOrderScopeService::NORMAL_ONLY) {
            $mobileScope->assertCartRouteId($user, $routeId);
        }
        if (
            $channel === 'mobile'
            && $scope === UserMobileOrderScopeService::ROUTE_ONLY
            && $mobileScope->isRouteSelectionLocked($user)
        ) {
            // Single locked route is inferred above. Multi-route reps may bootstrap a cart
            // without choosing yet; checkout resolves route from the selected customer.
            if ($routeId !== null || count($assignedRouteIds) <= 1) {
                $mobileScope->assertCartRouteId($user, $routeId);
            }
        }

        $cart = TemporaryCart::firstOrCreate(
            [
                'user_id' => $user->id,
                'channel' => $channel,
            ],
            [
                'organization_id' => (int) (
                    $user->organization_id
                    ?? \App\Support\OrganizationIdResolver::forBranch($branchId)
                ),
                'branch_id' => $branchId,
                'order_source' => $orderSource,
                'till_id' => $input['till_id'] ?? null,
                'route_id' => $routeId,
                'update_no' => 0,
            ]
        );

        if (! $cart->organization_id && $user->organization_id) {
            $cart->update(['organization_id' => (int) $user->organization_id]);
        }

        if ($cart->branch_id) {
            $this->userAccess()->assertBranchAccess($user, (int) $cart->branch_id);
        } else {
            $cart->update(['branch_id' => $branchId]);
        }

        if ($cart->order_source !== $orderSource) {
            $cart->update(['order_source' => $orderSource]);
        }

        // Sticky TemporaryCart rows survive overnight. When the cashier opens a new
        // till session (often a new till after "start day"), refresh till_id so
        // checkout does not fail with "Cart till does not match the open session."
        if (array_key_exists('till_id', $input)) {
            $nextTillId = $input['till_id'] !== null && $input['till_id'] !== ''
                ? (int) $input['till_id']
                : null;
            $currentTillId = $cart->till_id !== null ? (int) $cart->till_id : null;
            if ($currentTillId !== $nextTillId) {
                $cart->update(['till_id' => $nextTillId]);
            }
        }

        // Never wipe an existing cart route with null (multi-route bootstrap / reuse).
        if ($channel === 'mobile' && $routeId !== null && (int) $cart->route_id !== (int) $routeId) {
            $cart->update(['route_id' => $routeId]);
        }

        return $cart;
    }

    /**
     * Fast path for restore-to-cart: reuse sale line totals, bulk-insert cart lines,
     * and avoid N product/pricing round-trips that made held restores feel stuck.
     */
    protected function addRestoredSaleItemsToCart(
        TemporaryCart $cart,
        Sale $sale,
        User $user,
        CapabilityGate $gate,
        bool $skipStockReserve = false,
    ): void {
        $items = $sale->items;
        if ($items === null || $items->isEmpty()) {
            return;
        }

        $request = request();
        $orgId = (int) ($this->userAccess()->organizationId($user, $request) ?? 0);
        $branchId = (int) ($cart->branch_id ?? $user->branch_id ?? 0);
        $codes = $items->pluck('product_code')->filter()->map(fn ($c) => trim((string) $c))->unique()->values()->all();
        $products = Product::query()
            ->with('unit')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->whereIn('product_code', $codes)
            ->get()
            ->keyBy(fn (Product $product) => strtolower((string) $product->product_code));

        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $reserveOnCart = ($inventorySettings['reserve_stock_on_cart'] ?? true) && ! $skipStockReserve;
        $expiresAt = $reserveOnCart ? $this->reservationExpiresAtForUser($user, $inventorySettings) : null;

        $lineNo = (int) CartLine::where('cart_id', $cart->id)->max('line_no');
        $rows = [];
        $reserveJobs = [];

        foreach ($items as $item) {
            $code = strtolower(trim((string) $item->product_code));
            $product = $products->get($code);
            if (! $product) {
                throw new InvalidArgumentException("Product {$item->product_code} is not available for this cart.");
            }

            $lineNo++;
            $qty = (float) $item->quantity;
            $onWholesaleRetailFlag = (bool) ($item->on_wholesale_retail ?? 0);
            $unitPrice = (float) ($item->selling_price ?? 0);
            $amount = (float) ($item->amount ?? ($unitPrice * $qty));
            $productVat = (float) ($item->product_vat ?? 0);
            $discountGiven = (float) ($item->discount_given ?? 0);
            $updateCode = 'CLU-'.Str::upper(Str::random(12));

            $rows[] = [
                'cart_id' => $cart->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'unit_price' => $unitPrice,
                'display_unit_price' => $item->display_unit_price ?? null,
                'quantity' => $qty,
                'uom' => $item->uom ?? $product->unit?->uom_type,
                'product_vat' => $productVat,
                'amount' => $amount,
                'discount_given' => $discountGiven,
                'on_wholesale_retail' => $onWholesaleRetailFlag ? 1 : 0,
                'line_no' => $lineNo,
                'update_code' => $updateCode,
            ];

            if ($reserveOnCart) {
                $location = $this->resolveSaleLineStockLocation(
                    $cart->channel,
                    $inventorySettings,
                    $salesSettings,
                    $product,
                    $onWholesaleRetailFlag,
                );
                $reserveJobs[] = [
                    'product_code' => $product->product_code,
                    'quantity' => $qty,
                    'location' => $location,
                    'update_code' => $updateCode,
                ];
            }
        }

        if ($rows !== []) {
            CartLine::insert($rows);
            $cart->increment('update_no');
        }

        if ($reserveJobs === []) {
            return;
        }

        $createdLines = CartLine::query()
            ->where('cart_id', $cart->id)
            ->whereIn('update_code', array_column($reserveJobs, 'update_code'))
            ->get()
            ->keyBy('update_code');

        foreach ($reserveJobs as $job) {
            $line = $createdLines->get($job['update_code']);
            if (! $line) {
                continue;
            }
            $this->reserveStock(
                (int) $cart->branch_id,
                $job['product_code'],
                $job['quantity'],
                $job['location'],
                $user->id,
                $cart->id,
                $allowBelowStock,
                $line->id,
                $expiresAt,
            );
        }
    }

    /**
     * Bulk-insert draft POS lines (previous-order edit flush) without N× addCartLine.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function addDraftLinesToCart(
        TemporaryCart $cart,
        array $lines,
        User $user,
        CapabilityGate $gate,
        bool $skipStockReserve = false,
    ): void {
        if ($lines === []) {
            return;
        }

        $request = request();
        $orgId = (int) ($this->userAccess()->organizationId($user, $request) ?? 0);
        $codes = collect($lines)
            ->pluck('product_code')
            ->filter()
            ->map(fn ($c) => trim((string) $c))
            ->unique()
            ->values()
            ->all();
        if ($codes === []) {
            return;
        }

        $products = Product::query()
            ->with(['unit', 'vat'])
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->whereIn('product_code', $codes)
            ->get()
            ->keyBy(fn (Product $product) => strtolower((string) $product->product_code));

        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $reserveOnCart = ($inventorySettings['reserve_stock_on_cart'] ?? true) && ! $skipStockReserve;
        $expiresAt = $reserveOnCart ? $this->reservationExpiresAtForUser($user, $inventorySettings) : null;
        $discountService = app(\App\Services\Sales\DiscountApprovalService::class);
        $allowsManualDiscount = $discountService->allowsManualLineDiscount($salesSettings, $cart->order_source);
        $isOrderEdit = $discountService->cartIsOrderEditSession($cart);

        $lineNo = (int) CartLine::where('cart_id', $cart->id)->max('line_no');
        $rows = [];
        $reserveJobs = [];

        foreach ($lines as $line) {
            $code = strtolower(trim((string) ($line['product_code'] ?? '')));
            $product = $products->get($code);
            if (! $product) {
                throw new InvalidArgumentException("Product {$line['product_code']} is not available for this cart.");
            }

            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $onWholesaleRetailFlag = (bool) ($line['on_wholesale_retail'] ?? 0);
            $isRetail = $this->isRetailLine($product, $onWholesaleRetailFlag);
            $discountGiven = $this->resolveLineDiscountGiven(
                $salesSettings,
                (float) ($line['discount_given'] ?? 0),
            );
            if (! $allowsManualDiscount) {
                $discountGiven = 0;
            } elseif (! $isOrderEdit) {
                $discountService->assertDirectManualDiscountAllowed(
                    $user,
                    $salesSettings,
                    $discountGiven,
                    'discount_given',
                    $cart,
                );
            }

            [$unitPrice, $amount] = app(PosLinePricingService::class)->resolveLineAmounts(
                $product,
                $qty,
                $isRetail,
                $discountGiven,
                app(MobileRouteMarkupCheckoutService::class)->routeIdForCartPricing($cart, $salesSettings),
                array_key_exists('unit_price', $line) && $line['unit_price'] !== null
                    ? (float) $line['unit_price']
                    : null,
                SalesCheckoutSettings::allowsEditableUnitPrice($salesSettings, $cart->order_source),
            );

            [$unitPrice, $amount] = $this->applyAuthoritativeClientLineAmount(
                $line,
                $product,
                $qty,
                $isRetail,
                $unitPrice,
                $amount,
            );

            $grossForVat = max(0, $amount);
            $productVat = array_key_exists('product_vat', $line) && $line['product_vat'] !== null
                ? max(0, (float) $line['product_vat'])
                : SalesVatCalculator::vatFromInclusiveGross(
                    $grossForVat,
                    SalesVatCalculator::vatRateFromProduct($product),
                );

            $lineNo++;
            $updateCode = 'CLU-'.Str::upper(Str::random(12));
            $rows[] = [
                'cart_id' => $cart->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'unit_price' => $unitPrice,
                'display_unit_price' => array_key_exists('display_unit_price', $line) && $line['display_unit_price'] !== null
                    ? round((float) $line['display_unit_price'], 4)
                    : null,
                'quantity' => $qty,
                'uom' => $line['uom'] ?? $product->unit?->uom_type,
                'product_vat' => $productVat,
                'amount' => $amount,
                'discount_given' => $discountGiven,
                'on_wholesale_retail' => $onWholesaleRetailFlag ? 1 : 0,
                'line_no' => $lineNo,
                'update_code' => $updateCode,
            ];

            if ($reserveOnCart) {
                $location = $this->resolveSaleLineStockLocation(
                    $cart->channel,
                    $inventorySettings,
                    $salesSettings,
                    $product,
                    $onWholesaleRetailFlag,
                );
                $reserveJobs[] = [
                    'product_code' => $product->product_code,
                    'quantity' => $qty,
                    'location' => $location,
                    'update_code' => $updateCode,
                ];
            }
        }

        if ($rows !== []) {
            CartLine::insert($rows);
            $cart->increment('update_no');
        }

        if ($reserveJobs === []) {
            return;
        }

        $createdLines = CartLine::query()
            ->where('cart_id', $cart->id)
            ->whereIn('update_code', array_column($reserveJobs, 'update_code'))
            ->get()
            ->keyBy('update_code');

        foreach ($reserveJobs as $job) {
            $line = $createdLines->get($job['update_code']);
            if (! $line) {
                continue;
            }
            $this->reserveStock(
                (int) $cart->branch_id,
                $job['product_code'],
                $job['quantity'],
                $job['location'],
                $user->id,
                $cart->id,
                $allowBelowStock,
                $line->id,
                $expiresAt,
            );
        }
    }

    protected function addCartLine(
        TemporaryCart $cart,
        array $line,
        User $user,
        CapabilityGate $gate,
        bool $allowRestoredOrderDiscounts = false,
        bool $skipStockReserve = false,
    ): CartLine {
        $product = $this->findProductForCart($cart, (string) $line['product_code'], $user);
        $qty = (float) ($line['quantity'] ?? 1);
        $onWholesaleRetailFlag = (bool) ($line['on_wholesale_retail'] ?? 0);
        $isRetail = $this->isRetailLine($product, $onWholesaleRetailFlag);
        $salesSettings = $gate->moduleSettings('sales');
        $discountService = app(\App\Services\Sales\DiscountApprovalService::class);
        $discountGiven = $this->resolveLineDiscountGiven(
            $salesSettings,
            (float) ($line['discount_given'] ?? 0),
        );
        if (! $discountService->allowsManualLineDiscount($salesSettings, $cart->order_source)) {
            $discountGiven = 0;
        } elseif (! $allowRestoredOrderDiscounts && ! $discountService->cartIsOrderEditSession($cart)) {
            $discountService->assertDirectManualDiscountAllowed(
                $user,
                $salesSettings,
                $discountGiven,
                'discount_given',
                $cart,
            );
        }

        [$unitPrice, $amount] = app(PosLinePricingService::class)->resolveLineAmounts(
            $product,
            $qty,
            $isRetail,
            $discountGiven,
            app(MobileRouteMarkupCheckoutService::class)->routeIdForCartPricing($cart, $salesSettings),
            array_key_exists('unit_price', $line) ? (float) $line['unit_price'] : null,
            SalesCheckoutSettings::allowsEditableUnitPrice($salesSettings, $cart->order_source),
        );

        [$unitPrice, $amount] = $this->applyAuthoritativeClientLineAmount(
            $line,
            $product,
            $qty,
            $isRetail,
            $unitPrice,
            $amount,
        );

        $product->loadMissing('vat');
        $grossForVat = max(0, $amount);
        $productVat = array_key_exists('product_vat', $line) && $line['product_vat'] !== null
            ? max(0, (float) $line['product_vat'])
            : SalesVatCalculator::vatFromInclusiveGross(
                $grossForVat,
                SalesVatCalculator::vatRateFromProduct($product),
            );

        $settings = $gate->moduleSettings('inventory');
        $location = $this->resolveSaleLineStockLocation(
            $cart->channel,
            $settings,
            $salesSettings,
            $product,
            $onWholesaleRetailFlag,
        );

        $lineNo = (int) CartLine::where('cart_id', $cart->id)->max('line_no') + 1;

        $row = CartLine::create([
            'cart_id' => $cart->id,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'unit_price' => $unitPrice,
            'display_unit_price' => array_key_exists('display_unit_price', $line) && $line['display_unit_price'] !== null
                ? round((float) $line['display_unit_price'], 4)
                : null,
            'quantity' => $qty,
            'uom' => $line['uom'] ?? $product->unit?->uom_type,
            'product_vat' => $productVat,
            'amount' => $amount,
            'discount_given' => $discountGiven,
            'on_wholesale_retail' => $onWholesaleRetailFlag ? 1 : 0,
            'line_no' => $lineNo,
            'update_code' => $this->generateLineUpdateCode(),
        ]);

        if (($settings['reserve_stock_on_cart'] ?? true) && ! $skipStockReserve) {
            $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
            $this->reserveStock(
                (int) $cart->branch_id,
                $product->product_code,
                $qty,
                $location,
                $user->id,
                $cart->id,
                $allowBelowStock,
                $row->id,
                $this->reservationExpiresAtForUser($user, $settings),
            );
        }

        $cart->increment('update_no');

        return $row;
    }

    protected function updateCartLine(
        TemporaryCart $cart,
        string $lineRef,
        array $input,
        User $user,
        CapabilityGate $gate,
    ): CartLine {
        if (
            array_key_exists('update_no', $input)
            && (int) $input['update_no'] !== (int) $cart->update_no
        ) {
            throw new InvalidArgumentException('Cart was updated elsewhere. Refresh and try again.');
        }

        $row = $this->findCartLineByRef($cart, $lineRef);
        $productCode = array_key_exists('product_code', $input) && $input['product_code'] !== null
            ? (string) $input['product_code']
            : (string) $row->product_code;
        $product = $this->findProductForCart($cart, $productCode, $user);

        $qty = array_key_exists('quantity', $input) ? (float) $input['quantity'] : (float) $row->quantity;
        $onWholesaleRetailFlag = array_key_exists('on_wholesale_retail', $input)
            ? (bool) $input['on_wholesale_retail']
            : (bool) $row->on_wholesale_retail;
        $isRetail = $this->isRetailLine($product, $onWholesaleRetailFlag);
        $salesSettings = $gate->moduleSettings('sales');

        $discountGiven = array_key_exists('discount_given', $input)
            ? (float) $input['discount_given']
            : (float) $row->discount_given;
        $discountGiven = $this->resolveLineDiscountGiven($salesSettings, $discountGiven);
        $discountService = app(\App\Services\Sales\DiscountApprovalService::class);
        if (! $discountService->allowsManualLineDiscount($salesSettings, $cart->order_source)) {
            $discountGiven = 0;
        } elseif (array_key_exists('discount_given', $input)
            && ! $discountService->cartIsOrderEditSession($cart)) {
            $discountService->assertDirectManualDiscountAllowed(
                $user,
                $salesSettings,
                $discountGiven,
                'discount_given',
                $cart,
            );
        }

        [$unitPrice, $amount] = app(PosLinePricingService::class)->resolveLineAmounts(
            $product,
            $qty,
            $isRetail,
            $discountGiven,
            app(MobileRouteMarkupCheckoutService::class)->routeIdForCartPricing($cart, $salesSettings),
            array_key_exists('unit_price', $input) ? (float) $input['unit_price'] : (float) $row->unit_price,
            SalesCheckoutSettings::allowsEditableUnitPrice($salesSettings, $cart->order_source),
        );

        [$unitPrice, $amount] = $this->applyAuthoritativeClientLineAmount(
            $input,
            $product,
            $qty,
            $isRetail,
            $unitPrice,
            $amount,
        );

        $settings = $gate->moduleSettings('inventory');
        $location = $this->resolveSaleLineStockLocation(
            $cart->channel,
            $settings,
            $salesSettings,
            $product,
            $onWholesaleRetailFlag,
        );

        if ($settings['reserve_stock_on_cart'] ?? true) {
            $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
            $expiresAt = $this->reservationExpiresAtForUser($user, $settings);
            $branchId = (int) $cart->branch_id;
            $reserveQty = $qty;
            $reserveLocation = $location;
            $reserveProductCode = $product->product_code;
            $lineId = (int) $row->id;
            $cartId = (int) $cart->id;
            $userId = (int) $user->id;

            $replaceReservation = function () use (
                $lineId,
                $branchId,
                $reserveProductCode,
                $reserveQty,
                $reserveLocation,
                $userId,
                $cartId,
                $allowBelowStock,
                $expiresAt,
            ): void {
                $this->releaseLineReservation($lineId);
                $this->reserveStock(
                    $branchId,
                    $reserveProductCode,
                    $reserveQty,
                    $reserveLocation,
                    $userId,
                    $cartId,
                    $allowBelowStock,
                    $lineId,
                    $expiresAt,
                );
            };

            if (DB::transactionLevel() > 0) {
                $replaceReservation();
            } else {
                DB::transaction($replaceReservation, 5);
            }
        }

        $grossForVat = max(0, $amount);
        $product->loadMissing('vat');
        $productVat = array_key_exists('product_vat', $input) && $input['product_vat'] !== null
            ? max(0, (float) $input['product_vat'])
            : SalesVatCalculator::vatFromInclusiveGross(
                $grossForVat,
                SalesVatCalculator::vatRateFromProduct($product),
            );

        $row->update([
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'unit_price' => $unitPrice,
            'display_unit_price' => array_key_exists('display_unit_price', $input) && $input['display_unit_price'] !== null
                ? round((float) $input['display_unit_price'], 4)
                : $row->display_unit_price,
            'quantity' => $qty,
            'uom' => $input['uom'] ?? $row->uom ?? $product->unit?->uom_type,
            'product_vat' => $productVat,
            'amount' => $amount,
            'discount_given' => $discountGiven,
            'on_wholesale_retail' => $onWholesaleRetailFlag ? 1 : 0,
        ]);

        $cart->increment('update_no');

        return $row->fresh();
    }

    protected function removeCartLine(TemporaryCart $cart, string $lineRef): void
    {
        $row = $this->findCartLineByRef($cart, $lineRef);
        $this->releaseLineReservation($row->id);
        $row->delete();
        $cart->increment('update_no');
    }

    protected function clearCart(TemporaryCart $cart, ?User $user = null): void
    {
        $user ??= request()->user();
        $supersededSaleId = (int) ($cart->superseded_sale_id ?? 0);

        if ($user) {
            app(\App\Services\Notifications\ActionRequestService::class)->cancelAllPendingForCart(
                $cart,
                $user,
                'Cart was cleared.',
            );
        }

        $this->releaseCartReservations($cart->id);
        CartLine::where('cart_id', $cart->id)->delete();
        $cart->update([
            'order_discount' => 0,
            'discount_voucher_id' => null,
            'held_order_num' => null,
            'superseded_sale_id' => null,
        ]);
        $this->clearCartPaymentOptions($cart);
        $cart->increment('update_no');

        // Abandoning an in-progress POS edit must restore the receipt so ← can open it again.
        if ($supersededSaleId > 0 && $user) {
            $this->reinstateSupersededSale($supersededSaleId, $user);
        }
    }

    /**
     * Undo restoreHeldOrder when the edit cart is cleared without checkout.
     * New flow keeps the sale live (pos_editing_in_progress); older clients may
     * still have cancelled+archived tombstones that need full reinstate.
     */
    protected function reinstateSupersededSale(int $saleId, User $user): void
    {
        $sale = Sale::query()->with('items')->find($saleId);
        if (! $sale) {
            return;
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];

        // Live sale still visible while editing — put stock/journal back and clear edit flags.
        if (! empty($meta['pos_editing_in_progress'])
            && (string) $sale->status !== 'cancelled'
            && (int) ($sale->archived ?? 0) !== 1) {
            $shouldBalanceStock = (bool) ($meta['original_stock_balanced'] ?? true);
            unset(
                $meta['pos_editing_in_progress'],
                $meta['pos_editing_at'],
                $meta['pos_editing_by'],
                $meta['original_order_num'],
                $meta['original_status'],
                $meta['original_stock_balanced'],
            );

            $sale->update([
                'fulfillment_meta' => $meta === [] ? null : $meta,
            ]);

            $sale = $sale->fresh(['items']);
            if ($shouldBalanceStock && $sale && ! $sale->stock_balanced) {
                $this->deductSaleStockAfterReinstate($sale, $user);
            }
            if ($sale) {
                $gate = $this->erp->gateForUser($user);
                app(\App\Services\Accounting\SaleJournalService::class)->postIfEnabled($sale, $user, $gate);
            }

            return;
        }

        $originalOrderNum = (int) ($meta['original_order_num'] ?? 0);
        if ($originalOrderNum <= 0 || $originalOrderNum >= 9_000_000) {
            return;
        }

        // Only reinstate sales we cancelled for edit.
        if (($sale->status !== 'cancelled' && (int) ($sale->archived ?? 0) !== 1)
            || empty($meta['superseded_by_edit'])) {
            return;
        }

        $originalStatus = (string) ($meta['original_status'] ?? 'completed');
        if ($originalStatus === '' || $originalStatus === 'cancelled') {
            $originalStatus = 'completed';
        }
        $shouldBalanceStock = (bool) ($meta['original_stock_balanced'] ?? true);

        unset(
            $meta['superseded_by_edit'],
            $meta['superseded_at'],
            $meta['original_order_num'],
            $meta['original_status'],
            $meta['original_stock_balanced'],
            $meta['pos_editing_in_progress'],
            $meta['pos_editing_at'],
            $meta['pos_editing_by'],
        );

        $sale->update([
            'order_num' => $originalOrderNum,
            'status' => $originalStatus,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'archived' => 0,
            'fulfillment_meta' => $meta === [] ? null : $meta,
        ]);

        $sale = $sale->fresh(['items']);
        if ($shouldBalanceStock && $sale && ! $sale->stock_balanced) {
            $this->deductSaleStockAfterReinstate($sale, $user);
        }

        if ($sale) {
            $gate = $this->erp->gateForUser($user);
            app(\App\Services\Accounting\SaleJournalService::class)->postIfEnabled($sale, $user, $gate);
        }
    }

    protected function deductSaleStockAfterReinstate(Sale $sale, User $user): void
    {
        if ($sale->stock_balanced) {
            return;
        }

        $gate = $this->erp->gateForUser($user);
        $inventorySettings = $gate->moduleSettings('inventory');
        $salesSettings = $gate->moduleSettings('sales');
        $txnType = $this->saleTransactionType((string) ($sale->channel ?: 'pos'));
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);

        foreach ($sale->items ?? [] as $item) {
            $product = $this->orgProduct((int) $user->organization_id, (string) $item->product_code);
            $location = $product
                ? $this->resolveSaleLineStockLocation(
                    (string) $sale->channel,
                    $inventorySettings,
                    $salesSettings,
                    $product,
                    (bool) $item->on_wholesale_retail,
                )
                : $this->saleLineStockLocation(
                    (string) $sale->channel,
                    $inventorySettings,
                    $salesSettings,
                    (bool) $item->on_wholesale_retail,
                );

            $unitCost = max(0, (float) ($product?->last_cost_price ?? 0));

            $this->postStockLedger([
                'branch_id' => $sale->branch_id,
                'product_code' => $item->product_code,
                'stock_location' => $location,
                'transaction_type' => $txnType,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'quantity_change' => -abs((float) $item->quantity),
                'unit_cost' => $unitCost > 0 ? $unitCost : null,
                'notes' => 'Sale reinstated after abandoned POS edit',
                'created_by' => $user->id,
            ], $allowBelowStock);
        }

        $sale->update(['stock_balanced' => 1]);
        $this->releaseSaleReservations((int) $sale->id);
    }

    protected function findCartLineByRef(TemporaryCart $cart, string $lineRef): CartLine
    {
        $lineRef = trim((string) $lineRef);
        $query = CartLine::where('cart_id', $cart->id);

        $line = (clone $query)->where('update_code', $lineRef)->first();
        if ($line) {
            return $line;
        }

        if (ctype_digit($lineRef)) {
            return $query->where('id', (int) $lineRef)->firstOrFail();
        }

        abort(404);
    }

    protected function generateLineUpdateCode(): string
    {
        do {
            $code = 'CLU-'.Str::upper(Str::random(10));
        } while (CartLine::where('update_code', $code)->exists());

        return $code;
    }

    protected function resolveClientCartLineAmount(array $line, float $computedAmount): float
    {
        if (! array_key_exists('amount', $line)) {
            return round(max(0.0, $computedAmount), 2);
        }

        return \App\Services\Sales\CartLineAmountResolver::resolve($line['amount'], $computedAmount);
    }

    /**
     * When the POS workspace sends an authoritative line amount, keep it and align
     * stored unit price for display instead of naive unit×qty recomputation.
     *
     * @return array{0: float, 1: float}
     */
    protected function applyAuthoritativeClientLineAmount(
        array $line,
        Product $product,
        float $qty,
        bool $isRetail,
        float $unitPrice,
        float $computedAmount,
    ): array {
        if (! array_key_exists('amount', $line) || $line['amount'] === null || $line['amount'] === '') {
            return [$unitPrice, round(max(0.0, $computedAmount), 2)];
        }

        $finalAmount = $this->resolveClientCartLineAmount($line, $computedAmount);
        $clientAmount = round(max(0.0, (float) $line['amount']), 2);

        if (
            abs($finalAmount - $clientAmount) < 0.01
            && abs($finalAmount - round($computedAmount, 2)) > 0.01
        ) {
            $product->loadMissing('unit');
            $factor = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
            $entryQty = $factor > 1 && ! $isRetail ? $qty / $factor : $qty;
            if ($entryQty > 0) {
                $unitPrice = round($finalAmount / $entryQty, 4);
            }
        }

        return [$unitPrice, $finalAmount];
    }

    protected function resolveLineDiscountGiven(array $salesSettings, float $amount): float
    {
        if (! app(\App\Services\Sales\DiscountApprovalService::class)->allowsLineDiscountAmount($salesSettings)) {
            return 0;
        }

        return max(0, $amount);
    }

    protected function findProductForCart(TemporaryCart $cart, string $productCode, User $user): Product
    {
        $request = request();
        $orgId = (int) ($this->userAccess()->organizationId($user, $request) ?? 0);
        $branchId = (int) ($cart->branch_id ?? 0);
        if ($branchId <= 0) {
            $branchId = (int) (
                app(BranchStockService::class)->resolveBranchIdOptional($user, $request)
                ?? $user->branch_id
                ?? 0
            );
        }

        $product = app(ProductCatalogScopeService::class)->findAccessibleProduct(
            trim($productCode),
            $orgId,
            $branchId,
        );

        return $product->loadMissing('unit');
    }
}
