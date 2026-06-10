<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesPricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AddCartLineRequest;
use App\Http\Requests\Sales\StoreCartRequest;
use App\Http\Requests\Sales\UpdateCartLineRequest;
use App\Models\CartLine;
use App\Models\Product;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use InvalidArgumentException;

class CartOperationsController extends Controller
{
    use HandlesInventory;
    use HandlesPricing;

    public function __construct(protected ErpContext $erp) {}

    public function store(StoreCartRequest $request)
    {
        $cart = $this->getOrCreateCart($request->user(), $request->validated());
        $cart->load('lines');

        return response()->json($cart, 201);
    }

    public function show(int $cartId)
    {
        return response()->json($this->findCart($cartId, request()->user()));
    }

    public function update(\Illuminate\Http\Request $request, int $cartId)
    {
        $cart = $this->findCart($cartId, $request->user());
        $data = $request->validate([
            'route_id' => 'nullable|integer|exists:routes,id',
        ]);

        $cart->update([
            'route_id' => $data['route_id'] ?? null,
        ]);
        $cart->increment('update_no');

        return response()->json($cart->fresh('lines'));
    }

    public function addLine(AddCartLineRequest $request, int $cartId)
    {
        $cart = $this->findCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $line = $this->addCartLine($cart, $request->validated(), $request->user(), $gate);

        return response()->json($line, 201);
    }

    public function updateLine(UpdateCartLineRequest $request, int $cartId, int $lineId)
    {
        $cart = $this->findCart($cartId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $line = $this->updateCartLine($cart, $lineId, $request->validated(), $request->user(), $gate);

        return response()->json($cart->fresh('lines'));
    }

    public function deleteLine(int $cartId, int $lineId)
    {
        $cart = $this->findCart($cartId, request()->user());
        $this->removeCartLine($cart, $lineId);

        return response()->json($cart->fresh('lines'));
    }

    public function clear(int $cartId)
    {
        $this->clearCart($this->findCart($cartId, request()->user()));

        return response()->json(['ok' => true]);
    }

    protected function getOrCreateCart(User $user, array $input): TemporaryCart
    {
        $channel = $input['channel'] ?? 'pos';
        $gate = $this->erp->gateForUser($user);
        if (! $gate->channelEnabled($channel)) {
            throw new InvalidArgumentException("Channel [{$channel}] is not enabled for this organization.");
        }

        return TemporaryCart::firstOrCreate(
            [
                'user_id' => $user->id,
                'channel' => $channel,
            ],
            [
                'branch_id' => $input['branch_id'] ?? $user->branch_id,
                'till_id' => $input['till_id'] ?? null,
                'route_id' => $input['route_id'] ?? null,
                'update_no' => 0,
            ]
        );
    }

    protected function addCartLine(TemporaryCart $cart, array $line, User $user, CapabilityGate $gate): CartLine
    {
        $product = Product::with('unit')->where('product_code', $line['product_code'])->firstOrFail();
        $qty = (float) ($line['quantity'] ?? 1);
        $onWholesaleRetailFlag = (bool) ($line['on_wholesale_retail'] ?? 0);
        $isRetail = $this->isRetailLine($product, $onWholesaleRetailFlag);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        if ($unitPrice <= 0) {
            $unitPrice = $this->lineUnitPrice($product, 1, $isRetail, $cart->route_id) / max($qty, 1);
        }
        $amount = round($unitPrice * $qty, 2);

        $salesSettings = $gate->moduleSettings('sales');
        $discountGiven = ! empty($salesSettings['allow_discounts'])
            ? max(0, (float) ($line['discount_given'] ?? 0))
            : 0;

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
            'product_vat' => $line['product_vat'] ?? 0,
            'amount' => $amount,
            'discount_given' => $discountGiven,
            'on_wholesale_retail' => $onWholesaleRetailFlag ? 1 : 0,
            'line_no' => $lineNo,
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
        int $lineId,
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

        $row = CartLine::where('cart_id', $cart->id)->where('id', $lineId)->firstOrFail();
        $product = Product::with('unit')->where('product_code', $row->product_code)->firstOrFail();

        $qty = array_key_exists('quantity', $input) ? (float) $input['quantity'] : (float) $row->quantity;
        $onWholesaleRetailFlag = array_key_exists('on_wholesale_retail', $input)
            ? (bool) $input['on_wholesale_retail']
            : (bool) $row->on_wholesale_retail;
        $isRetail = $this->isRetailLine($product, $onWholesaleRetailFlag);

        $unitPrice = array_key_exists('unit_price', $input)
            ? (float) $input['unit_price']
            : (float) $row->unit_price;
        if ($unitPrice <= 0) {
            $unitPrice = $this->lineUnitPrice($product, 1, $isRetail, $cart->route_id) / max($qty, 1);
        }

        $salesSettings = $gate->moduleSettings('sales');
        $discountGiven = array_key_exists('discount_given', $input)
            ? max(0, (float) $input['discount_given'])
            : (float) $row->discount_given;
        if (empty($salesSettings['allow_discounts'])) {
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

        $row->update([
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'uom' => $input['uom'] ?? $row->uom ?? $product->unit?->uom_type,
            'product_vat' => $input['product_vat'] ?? $row->product_vat ?? 0,
            'amount' => round($unitPrice * $qty, 2),
            'discount_given' => $discountGiven,
            'on_wholesale_retail' => $onWholesaleRetailFlag ? 1 : 0,
        ]);

        $cart->increment('update_no');

        return $row->fresh();
    }

    protected function removeCartLine(TemporaryCart $cart, int $lineId): void
    {
        $row = CartLine::where('cart_id', $cart->id)->where('id', $lineId)->firstOrFail();
        $this->releaseLineReservation($row->id);
        $row->delete();
        $cart->increment('update_no');
    }

    protected function clearCart(TemporaryCart $cart): void
    {
        $this->releaseCartReservations($cart->id);
        CartLine::where('cart_id', $cart->id)->delete();
        $cart->increment('update_no');
    }

    protected function findCart(int $cartId, ?User $user = null): TemporaryCart
    {
        $cart = TemporaryCart::with('lines')->findOrFail($cartId);
        if ($user && (int) $cart->user_id !== (int) $user->id) {
            abort(403, 'This cart belongs to another cashier.');
        }

        return $cart;
    }
}
