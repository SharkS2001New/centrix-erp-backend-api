<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesPricing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AddCartLineRequest;
use App\Http\Requests\Sales\StoreCartRequest;
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

        return response()->json($cart, 201);
    }

    public function show(int $cartId)
    {
        return response()->json($this->findCart($cartId));
    }

    public function addLine(AddCartLineRequest $request, int $cartId)
    {
        $cart = $this->findCart($cartId);
        $gate = $this->erp->gateForUser($request->user());
        $line = $this->addCartLine($cart, $request->validated(), $request->user(), $gate);

        return response()->json($line, 201);
    }

    public function clear(int $cartId)
    {
        $this->clearCart($this->findCart($cartId));

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
        $isRetail = $this->isRetailLine($product, (bool) ($line['on_wholesale_retail'] ?? 0));
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        if ($unitPrice <= 0) {
            $unitPrice = $this->lineUnitPrice($product, 1, $isRetail, $cart->route_id) / max($qty, 1);
        }
        $amount = round($unitPrice * $qty, 2);

        $settings = $gate->moduleSettings('inventory');
        $location = $this->saleStockLocation($cart->channel, $settings);

        if ($settings['reserve_stock_on_cart'] ?? true) {
            $this->reserveStock(
                (int) $cart->branch_id,
                $product->product_code,
                $qty,
                $location,
                $user->id,
                $cart->id
            );
        }

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
            'on_wholesale_retail' => $isRetail ? 1 : 0,
            'line_no' => $lineNo,
        ]);

        $cart->increment('update_no');

        return $row;
    }

    protected function clearCart(TemporaryCart $cart): void
    {
        $this->releaseCartReservations($cart->id);
        CartLine::where('cart_id', $cart->id)->delete();
        $cart->increment('update_no');
    }

    protected function findCart(int $cartId): TemporaryCart
    {
        return TemporaryCart::with('lines')->findOrFail($cartId);
    }
}
