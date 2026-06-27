<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartPayments;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesMpesaPayments;
use App\Models\CartLine;
use App\Models\TemporaryCart;
use App\Models\User;

class UserCartCleanupService
{
    use HandlesCartPayments;
    use HandlesInventory;
    use HandlesMpesaPayments;

    public function clearAllForUser(User $user): void
    {
        $carts = TemporaryCart::query()
            ->where('user_id', $user->id)
            ->get();

        foreach ($carts as $cart) {
            $this->clearCart($cart);
        }
    }

    protected function clearCart(TemporaryCart $cart): void
    {
        $this->releaseExpiredReservations($cart->id);
        $this->releaseCartReservations($cart->id);
        CartLine::where('cart_id', $cart->id)->delete();
        $cart->update([
            'order_discount' => 0,
            'discount_voucher_id' => null,
            'route_id' => null,
            'held_order_num' => null,
            'superseded_sale_id' => null,
        ]);
        $this->clearCartPaymentOptions($cart);
        if (method_exists($this, 'releaseCartMpesaPayments')) {
            $this->releaseCartMpesaPayments($cart);
        }
        $cart->increment('update_no');
    }
}
