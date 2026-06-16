<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\TemporaryCart;
use App\Models\User;

trait HandlesCartAccess
{
    use HandlesBranchScope;

    protected function findOwnedCart(int $cartId, User $user): TemporaryCart
    {
        $cart = TemporaryCart::with('lines')->findOrFail($cartId);
        if ((int) $cart->user_id !== (int) $user->id) {
            abort(403, 'This cart belongs to another cashier.');
        }

        $this->userAccess()->assertBranchAccess(
            $user,
            $cart->branch_id ? (int) $cart->branch_id : null,
            'This cart belongs to another branch.',
        );

        return $cart;
    }
}
