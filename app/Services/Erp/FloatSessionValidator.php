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
     * When float is required for the workspace, an open session id is mandatory.
     * When float is not required (typical backoffice), still soft-attach the cashier's
     * open session if one exists — so the sale counts on X / Z / EOD until close.
     *
     * @param  array<string, mixed>  $input
     */
    public function resolveForCheckout(TemporaryCart $cart, User $user, array $input): ?int
    {
        $sessionId = isset($input['float_session_id']) ? (int) $input['float_session_id'] : null;
        if ($sessionId !== null && $sessionId <= 0) {
            $sessionId = null;
        }
        $requiresFloat = $this->requiresFloatForCheckout($cart, $input);

        if (! $requiresFloat) {
            // Soft-attach: use provided id, else the cashier's open session for this branch.
            if (! $sessionId) {
                $sessionId = $this->findOpenSessionIdForUser($user, $cart);
            }
            if (! $sessionId) {
                return null;
            }
        } elseif (! $sessionId) {
            throw new InvalidArgumentException(
                'Open a till session and declare your operating float before completing POS sales.',
            );
        }

        $session = TillFloatSession::find($sessionId);
        if (! $session || ! in_array(strtolower((string) $session->status), ['open'], true)) {
            if ($requiresFloat) {
                throw new InvalidArgumentException('Till session is not open.');
            }

            return null;
        }

        if ((int) $session->cashier_id !== (int) $user->id) {
            if ($requiresFloat) {
                throw new InvalidArgumentException('Till session belongs to another cashier.');
            }

            return null;
        }

        $branchId = $cart->branch_id ?? $user->branch_id;
        if ($branchId && (int) $session->branch_id !== (int) $branchId) {
            if ($requiresFloat) {
                throw new InvalidArgumentException('Till session belongs to another branch.');
            }

            return null;
        }

        if ($cart->till_id && (int) $cart->till_id !== (int) $session->till_id) {
            // TemporaryCart is reused per cashier+channel and often keeps yesterday's till.
            // Align to the open session when the cashier/branch already match.
            $cart->till_id = (int) $session->till_id;
            $cart->save();
        }

        return (int) $session->id;
    }

    /**
     * Open till session for this cashier (and cart branch when known).
     */
    protected function findOpenSessionIdForUser(User $user, TemporaryCart $cart): ?int
    {
        if (! $this->tillFloatEnabled()) {
            return null;
        }

        $query = TillFloatSession::query()
            ->where('cashier_id', $user->id)
            ->whereRaw('LOWER(status) = ?', ['open'])
            ->orderByDesc('id');

        $branchId = $cart->branch_id ?? $user->branch_id;
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $session = $query->first();

        return $session ? (int) $session->id : null;
    }
}
