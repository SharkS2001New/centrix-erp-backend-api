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

    public function requireBackofficeTillFloat(): bool
    {
        return (bool) ($this->gate->moduleSettings('sales')['require_backoffice_till_float'] ?? false);
    }

    /** Whether any till-float workflow is enabled for this organization. */
    public function tillFloatEnabled(): bool
    {
        return $this->requirePosTillFloat() || $this->requireBackofficeTillFloat();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function requiresFloatForCheckout(TemporaryCart $cart, array $input): bool
    {
        $channel = strtolower((string) $cart->channel);
        if ($channel === 'mobile') {
            return false;
        }

        $workspace = strtolower((string) ($input['sales_workspace'] ?? 'pos'));

        if ($workspace === 'backoffice') {
            return $this->requireBackofficeTillFloat();
        }

        return $this->requirePosTillFloat() && $channel === 'pos';
    }

    /**
     * Resolve and validate float session for checkout.
     *
     * @param  array<string, mixed>  $input
     */
    public function resolveForCheckout(TemporaryCart $cart, User $user, array $input): ?int
    {
        $sessionId = isset($input['float_session_id']) ? (int) $input['float_session_id'] : null;
        $requiresFloat = $this->requiresFloatForCheckout($cart, $input);

        if (! $requiresFloat) {
            if ($sessionId) {
                throw new InvalidArgumentException(
                    'Till float sessions are not used when operating float is disabled for this workspace.',
                );
            }

            return null;
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
