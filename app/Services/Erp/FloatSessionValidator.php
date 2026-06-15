<?php

namespace App\Services\Erp;

use App\Models\TemporaryCart;
use App\Models\TillFloatSession;
use App\Models\User;
use InvalidArgumentException;

class FloatSessionValidator
{
    public function __construct(protected CapabilityGate $gate) {}

    public static function forUser(User $user): self
    {
        return new self(app(ErpContext::class)->gateForUser($user));
    }

    public function requirePosTillFloat(): bool
    {
        return (bool) ($this->gate->moduleSettings('sales')['require_pos_till_float'] ?? false);
    }

    /**
     * Resolve and validate float session for checkout.
     *
     * @param  array<string, mixed>  $input
     */
    public function resolveForCheckout(TemporaryCart $cart, User $user, array $input): ?int
    {
        $sessionId = isset($input['float_session_id']) ? (int) $input['float_session_id'] : null;
        $isPosChannel = strtolower((string) $cart->channel) === 'pos';

        if (! $this->requirePosTillFloat() || ! $isPosChannel) {
            return $sessionId ?: null;
        }

        if (! $sessionId) {
            throw new InvalidArgumentException(
                'Open a till session and declare your operating float before completing POS sales.',
            );
        }

        $session = TillFloatSession::find($sessionId);
        if (! $session || ! in_array(strtolower((string) $session->status), ['open'], true)) {
            throw new InvalidArgumentException('Till session is not open.');
        }

        if ((int) $session->cashier_id !== (int) $user->id) {
            throw new InvalidArgumentException('Till session belongs to another cashier.');
        }

        if ($cart->till_id && (int) $cart->till_id !== (int) $session->till_id) {
            throw new InvalidArgumentException('Cart till does not match the open session.');
        }

        $branchId = $cart->branch_id ?? $user->branch_id;
        if ($branchId && (int) $session->branch_id !== (int) $branchId) {
            throw new InvalidArgumentException('Till session belongs to another branch.');
        }

        return $sessionId;
    }
}
