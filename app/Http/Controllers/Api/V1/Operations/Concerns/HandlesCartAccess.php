<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Sales\OrderNumberAllocator;

trait HandlesCartAccess
{
    use HandlesBranchScope;

    protected function findOwnedCart(int $cartId, User $user, bool $withLines = true): TemporaryCart
    {
        $query = TemporaryCart::query();
        if ($withLines) {
            $query->with('lines');
        }

        $cart = $query->findOrFail($cartId);
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

    /** @return array<string, mixed> */
    protected function presentCart(
        TemporaryCart $cart,
        ?User $user = null,
        array $extra = [],
        bool $includeNextOrderNum = true,
    ): array {
        $user ??= request()->user();
        $cart->loadMissing('lines');
        $payload = array_merge($cart->toArray(), $extra);

        if ($user && $includeNextOrderNum) {
            $payload['next_order_num'] = app(OrderNumberAllocator::class)
                ->nextForOrganization((int) $user->organization_id);
        }

        return $payload;
    }
}
