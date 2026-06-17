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
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Kra\SalesVatCalculator;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Sales\OrderSourceResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    protected function cartResponse(TemporaryCart $cart, User $user, int $status = 200, array $extra = [])
    {
        return response()->json($this->presentCart($cart, $user, $extra), $status);
    }

    public function store(StoreCartRequest $request)
    {
        $cart = $this->getOrCreateCart($request->user(), $request->validated());
        $cart->load('lines');

        return $this->cartResponse($cart, $request->user(), 201);
    }

    public function show(int $cartId)
    {
        $user = request()->user();

        return $this->cartResponse($this->findOwnedCart($cartId, $user), $user);
    }

    public function update(\Illuminate\Http\Request $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $salesSettings = $gate->moduleSettings('sales');
        $data = $request->validate([
            'route_id' => 'nullable|integer|exists:routes,id',
            'order_discount' => 'sometimes|numeric|min:0',
        ]);

        $updates = [];
        if (array_key_exists('route_id', $data)) {
            $updates['route_id'] = $data['route_id'] ?? null;
        }
        if (array_key_exists('order_discount', $data)) {
            $updates['order_discount'] = ! empty($salesSettings['enable_order_discount'])
                ? max(0, (float) $data['order_discount'])
                : 0;
        }

        if ($updates !== []) {
            $cart->update($updates);
            $cart->increment('update_no');
        }

        return $this->cartResponse($cart->fresh('lines'), $request->user());
    }

    public function addLine(AddCartLineRequest $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $line = $this->addCartLine($cart, $request->validated(), $request->user(), $gate);

        return response()->json($line, 201);
    }

    public function updateLine(UpdateCartLineRequest $request, int $cartId, string $lineRef)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $line = $this->updateCartLine($cart, $lineRef, $request->validated(), $request->user(), $gate);

        return $this->cartResponse($cart->fresh('lines'), $request->user());
    }

    public function deleteLine(int $cartId, string $lineRef)
    {
        $cart = $this->findOwnedCart($cartId, request()->user());
        $this->removeCartLine($cart, $lineRef);

        return $this->cartResponse($cart->fresh('lines'), request()->user());
    }

    public function clear(int $cartId)
    {
        $this->clearCart($this->findOwnedCart($cartId, request()->user()));

        return response()->json(['ok' => true]);
    }

    public function restoreHeldOrder(Request $request, int $saleId)
    {
        $user = $request->user();
        $sale = $this->findScopedSale($saleId, $user)->load('items');

        if ($sale->status !== 'held') {
            throw new InvalidArgumentException('Only held orders can be restored to the cart.');
        }

        $channel = $sale->channel ?: 'pos';
        $gate = $this->erp->gateForUser($user);
        if (! $gate->channelEnabled($channel)) {
            throw new InvalidArgumentException("Channel [{$channel}] is not enabled for this organization.");
        }

        $cart = $this->getOrCreateCart($user, [
            'channel' => $channel,
            'order_source' => $sale->order_source ?? $channel,
            'branch_id' => $sale->branch_id ?? $user->branch_id,
            'route_id' => $sale->route_id,
        ]);

        if ($cart->lines()->exists() && ! $request->boolean('replace')) {
            throw new InvalidArgumentException(
                'Your cart already has items. Clear it first or confirm replace.',
            );
        }

        $cart = DB::transaction(function () use ($cart, $sale, $user, $gate, $request) {
            if ($cart->lines()->exists()) {
                $this->clearCart($cart);
            }

            $cart->update([
                'route_id' => $sale->route_id,
                'order_discount' => (float) ($sale->order_discount ?? 0),
            ]);

            foreach ($sale->items as $item) {
                $this->addCartLine($cart, [
                    'product_code' => $item->product_code,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->selling_price,
                    'uom' => $item->uom,
                    'product_vat' => $item->product_vat,
                    'discount_given' => $item->discount_given,
                    'on_wholesale_retail' => $item->on_wholesale_retail,
                ], $user, $gate);
            }

            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ]);

            $this->reverseSaleJournalIfPosted($sale, $user);

            return $cart->fresh('lines');
        });

        return $this->cartResponse($cart, $user, 200, [
            'held_order_num' => (int) $sale->order_num,
        ]);
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
            if ($term === '') {
                return response()->json(['data' => []]);
            }
            $like = '%'.$term.'%';
            $query->where(function ($builder) use ($like, $term) {
                $builder
                    ->where('customer_name', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('additional_phone', 'like', $like);
                if (ctype_digit($term)) {
                    $builder->orWhere('customer_num', 'like', $like);
                }
            });
        }

        $perPage = min((int) ($data['per_page'] ?? 20), 50);
        $rows = $query
            ->orderBy('customer_name')
            ->limit($perPage)
            ->get([
                'customer_num',
                'customer_name',
                'phone_number',
                'credit_limit',
                'current_balance',
                'customer_status',
            ]);

        return response()->json(['data' => $rows]);
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

    public function attachLoyaltyCard(Request $request, int $cartId)
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
            'cart' => $this->presentCart($cart->fresh('lines'), $request->user()),
            'loyalty' => [
                'loyalty_card_id' => $card->id,
                'card_number' => $card->card_number,
                'customer_num' => $card->customer_num,
                'customer_name' => $card->customer?->customer_name,
                'points_balance' => (float) $card->points_balance,
            ],
        ]);
    }

    public function applyVoucherPayment(Request $request, int $cartId)
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

    public function applyPointsPayment(Request $request, int $cartId)
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

    public function updateCartPaymentExtras(Request $request, int $cartId)
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

    public function clearCartPayments(int $cartId)
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

        $sale->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
        ]);

        $this->reverseSaleJournalIfPosted($sale, $user);

        return response()->json($sale->fresh());
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

    protected function getOrCreateCart(User $user, array $input): TemporaryCart
    {
        $channel = $input['channel'] ?? 'pos';
        $token = $user->currentAccessToken();
        $orderSource = app(OrderSourceResolver::class)->defaultForCart($input, $token);
        $gate = $this->erp->gateForUser($user);
        if (! $gate->channelEnabled($channel)) {
            throw new InvalidArgumentException("Channel [{$channel}] is not enabled for this organization.");
        }

        $branchId = $this->userAccess()->resolveBranchId($user, $input['branch_id'] ?? null);

        $cart = TemporaryCart::firstOrCreate(
            [
                'user_id' => $user->id,
                'channel' => $channel,
            ],
            [
                'branch_id' => $branchId,
                'order_source' => $orderSource,
                'till_id' => $input['till_id'] ?? null,
                'route_id' => $input['route_id'] ?? null,
                'update_no' => 0,
            ]
        );

        if ($cart->branch_id) {
            $this->userAccess()->assertBranchAccess($user, (int) $cart->branch_id);
        } else {
            $cart->update(['branch_id' => $branchId]);
        }

        if ($cart->order_source !== $orderSource) {
            $cart->update(['order_source' => $orderSource]);
        }

        return $cart;
    }

    protected function addCartLine(TemporaryCart $cart, array $line, User $user, CapabilityGate $gate): CartLine
    {
        $product = Product::with('unit')->where('product_code', $line['product_code'])->firstOrFail();
        $qty = (float) ($line['quantity'] ?? 1);
        $onWholesaleRetailFlag = (bool) ($line['on_wholesale_retail'] ?? 0);
        $isRetail = $this->isRetailLine($product, $onWholesaleRetailFlag);
        $salesSettings = $gate->moduleSettings('sales');
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        if ($unitPrice <= 0 || empty($salesSettings['allow_edit_unit_price'])) {
            $unitPrice = $this->lineUnitPrice($product, 1, $isRetail, $cart->route_id) / max($qty, 1);
        }
        $amount = round($unitPrice * $qty, 2);

        $discountGiven = $this->resolveLineDiscountGiven(
            $salesSettings,
            (float) ($line['discount_given'] ?? 0),
        );
        if (empty($salesSettings['allow_edit_line_discount'])) {
            $discountGiven = 0;
        }

        $product->loadMissing('vat');
        $grossForVat = max(0, $amount - $discountGiven);
        $productVat = array_key_exists('product_vat', $line) && $line['product_vat'] !== null
            ? max(0, (float) $line['product_vat'])
            : SalesVatCalculator::vatFromInclusiveGross(
                $grossForVat,
                SalesVatCalculator::vatRateFromProduct($product),
            );

        $settings = $gate->moduleSettings('inventory');
        $stockAsRetail = $this->stockRouteAsRetail($product, $onWholesaleRetailFlag, $salesSettings);
        $location = $this->saleLineStockLocation($cart->channel, $settings, $salesSettings, $stockAsRetail);

        $lineNo = (int) CartLine::where('cart_id', $cart->id)->max('line_no') + 1;

        $row = CartLine::create([
            'cart_id' => $cart->id,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'uom' => $line['uom'] ?? $product->unit?->uom_type,
            'product_vat' => $productVat,
            'amount' => $amount,
            'discount_given' => $discountGiven,
            'on_wholesale_retail' => $onWholesaleRetailFlag ? 1 : 0,
            'line_no' => $lineNo,
            'update_code' => $this->generateLineUpdateCode(),
        ]);

        if ($settings['reserve_stock_on_cart'] ?? true) {
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
        $product = Product::with('unit')->where('product_code', $row->product_code)->firstOrFail();

        $qty = array_key_exists('quantity', $input) ? (float) $input['quantity'] : (float) $row->quantity;
        $onWholesaleRetailFlag = array_key_exists('on_wholesale_retail', $input)
            ? (bool) $input['on_wholesale_retail']
            : (bool) $row->on_wholesale_retail;
        $isRetail = $this->isRetailLine($product, $onWholesaleRetailFlag);
        $salesSettings = $gate->moduleSettings('sales');

        $unitPrice = array_key_exists('unit_price', $input)
            ? (float) $input['unit_price']
            : (float) $row->unit_price;
        if ($unitPrice <= 0 || empty($salesSettings['allow_edit_unit_price'])) {
            $unitPrice = $this->lineUnitPrice($product, 1, $isRetail, $cart->route_id) / max($qty, 1);
        }

        $discountGiven = array_key_exists('discount_given', $input)
            ? (float) $input['discount_given']
            : (float) $row->discount_given;
        $discountGiven = $this->resolveLineDiscountGiven($salesSettings, $discountGiven);
        if (empty($salesSettings['allow_edit_line_discount'])) {
            $discountGiven = 0;
        }

        $settings = $gate->moduleSettings('inventory');
        $stockAsRetail = $this->stockRouteAsRetail($product, $onWholesaleRetailFlag, $salesSettings);
        $location = $this->saleLineStockLocation($cart->channel, $settings, $salesSettings, $stockAsRetail);

        if ($settings['reserve_stock_on_cart'] ?? true) {
            $this->releaseLineReservation($row->id);
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
            );
        }

        $amount = round($unitPrice * $qty, 2);
        $grossForVat = max(0, $amount - $discountGiven);
        $product->loadMissing('vat');
        $productVat = array_key_exists('product_vat', $input) && $input['product_vat'] !== null
            ? max(0, (float) $input['product_vat'])
            : SalesVatCalculator::vatFromInclusiveGross(
                $grossForVat,
                SalesVatCalculator::vatRateFromProduct($product),
            );

        $row->update([
            'unit_price' => $unitPrice,
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

    protected function clearCart(TemporaryCart $cart): void
    {
        $this->releaseCartReservations($cart->id);
        CartLine::where('cart_id', $cart->id)->delete();
        $cart->update(['order_discount' => 0, 'discount_voucher_id' => null]);
        $this->clearCartPaymentOptions($cart);
        $cart->increment('update_no');
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

    protected function resolveLineDiscountGiven(array $salesSettings, float $amount): float
    {
        if (empty($salesSettings['allow_discounts'])) {
            return 0;
        }

        return max(0, $amount);
    }
}
