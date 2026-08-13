<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Org setting: append new mobile sales for a registered customer onto
 * their open mobile order from the same calendar day (same branch) instead of a new ticket.
 * POS and backoffice checkouts are never affected.
 */
class SameDayCustomerOrderService
{
    public function enabled(array $salesSettings): bool
    {
        return ! empty($salesSettings['append_same_day_customer_orders']);
    }

    /**
     * Latest non-cancelled/expired mobile sale for this customer today at the branch.
     */
    public function findOpenOrderToday(
        User $user,
        int $customerNum,
        ?int $branchId = null,
        ?string $channel = 'mobile',
    ): ?Sale {
        if ($customerNum <= 0) {
            return null;
        }

        $orgId = (int) ($user->organization_id ?? 0);
        if ($orgId <= 0) {
            return null;
        }

        // Feature is mobile-only — never match POS/backoffice tickets.
        $channel = strtolower(trim((string) ($channel ?: 'mobile')));
        if ($channel !== 'mobile') {
            return null;
        }

        $tz = (string) config('app.timezone', 'Africa/Nairobi');
        $dayStart = Carbon::now($tz)->startOfDay()->utc();
        $dayEnd = Carbon::now($tz)->endOfDay()->utc();

        $query = Sale::query()
            ->where('organization_id', $orgId)
            ->where('customer_num', $customerNum)
            ->where('channel', 'mobile')
            ->whereNotIn('status', ['cancelled', 'expired', 'held', 'draft'])
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->orderByDesc('id');

        $resolvedBranch = $branchId ?? ($user->branch_id ? (int) $user->branch_id : null);
        if ($resolvedBranch) {
            $query->where('branch_id', $resolvedBranch);
        }

        return $query->first();
    }

    /**
     * When setting is on and mobile checkout has a customer but is not already an edit
     * of today's mobile order, return that order so checkout can continue it.
     *
     * @param  array<string, mixed>  $salesSettings
     */
    public function resolveAppendTarget(
        CapabilityGate $gate,
        User $user,
        array $salesSettings,
        ?int $customerNum,
        ?int $branchId,
        string $channel,
        ?int $alreadySupersededSaleId,
        ?int $heldOrderNum,
    ): ?Sale {
        if (! $this->enabled($salesSettings)) {
            return null;
        }
        if (! $customerNum || $customerNum <= 0) {
            return null;
        }
        // Mobile field sales only — POS / backoffice always create a new ticket.
        if (strtolower(trim($channel)) !== 'mobile') {
            return null;
        }

        $existing = $this->findOpenOrderToday($user, $customerNum, $branchId, 'mobile');
        if (! $existing) {
            return null;
        }

        // Already editing this (or another) order — leave explicit edit sessions alone.
        if ($alreadySupersededSaleId && (int) $alreadySupersededSaleId === (int) $existing->id) {
            return null;
        }
        if ($heldOrderNum && (int) $heldOrderNum === (int) $existing->order_num) {
            return null;
        }
        if ($alreadySupersededSaleId || $heldOrderNum) {
            return null;
        }

        return $existing;
    }

    /**
     * Build synthetic cart-line shaped objects from a prior sale's items.
     *
     * @return Collection<int, object>
     */
    public function priorItemsAsCheckoutLines(Sale $sale): Collection
    {
        $sale->loadMissing('items');

        return collect($sale->items ?? [])->map(function ($item) {
            return (object) [
                'product_code' => $item->product_code,
                'quantity' => (float) $item->quantity,
                'uom' => $item->uom,
                'unit_price' => (float) $item->selling_price,
                'display_unit_price' => $item->display_unit_price !== null
                    ? (float) $item->display_unit_price
                    : null,
                'discount_given' => (float) ($item->discount_given ?? 0),
                'product_vat' => (float) ($item->product_vat ?? 0),
                'amount' => (float) $item->amount,
                'on_wholesale_retail' => (int) ($item->on_wholesale_retail ?? 0),
            ];
        })->values();
    }

    /**
     * Collapse checkout lines by product_code + wholesale/retail flag so same-day
     * mobile appends (and restored+new carts) store one sale_item per SKU.
     * Mirrors SaleMergeService::foldSourceIntoTarget / POS combine-identical-lines.
     *
     * @param  Collection<int, object>  $lines
     * @return Collection<int, object>
     */
    public function foldCheckoutLinesBySku(Collection $lines): Collection
    {
        $folded = [];

        foreach ($lines as $line) {
            $code = trim((string) ($line->product_code ?? ''));
            if ($code === '') {
                $folded[] = $line;

                continue;
            }

            $retail = (int) ($line->on_wholesale_retail ?? 0) ? 1 : 0;
            $key = $code.'|'.$retail;

            if (! isset($folded[$key])) {
                $folded[$key] = (object) [
                    'product_code' => $code,
                    'quantity' => (float) ($line->quantity ?? 0),
                    'uom' => $line->uom ?? null,
                    'unit_price' => (float) ($line->unit_price ?? 0),
                    'display_unit_price' => isset($line->display_unit_price) && $line->display_unit_price !== null
                        ? (float) $line->display_unit_price
                        : null,
                    'discount_given' => (float) ($line->discount_given ?? 0),
                    'product_vat' => (float) ($line->product_vat ?? 0),
                    'amount' => (float) ($line->amount ?? 0),
                    'on_wholesale_retail' => $retail,
                ];

                continue;
            }

            $existing = $folded[$key];
            $newQty = round((float) $existing->quantity + (float) ($line->quantity ?? 0), 4);
            $newAmount = round((float) $existing->amount + (float) ($line->amount ?? 0), 2);
            $newDiscount = round((float) $existing->discount_given + (float) ($line->discount_given ?? 0), 2);
            $newVat = round((float) $existing->product_vat + (float) ($line->product_vat ?? 0), 2);
            $unitPrice = $newQty > 0.0001
                ? round($newAmount / $newQty, 4)
                : (float) $existing->unit_price;

            $display = $existing->display_unit_price !== null
                ? (float) $existing->display_unit_price
                : 0.0;
            $sourceDisplay = isset($line->display_unit_price) && $line->display_unit_price !== null
                ? (float) $line->display_unit_price
                : 0.0;
            if ($display <= 0 && $sourceDisplay > 0) {
                $display = $sourceDisplay;
            } elseif ($display <= 0) {
                $display = $unitPrice;
            }

            $existing->quantity = $newQty;
            $existing->amount = $newAmount;
            $existing->discount_given = $newDiscount;
            $existing->product_vat = $newVat;
            $existing->unit_price = $unitPrice;
            $existing->display_unit_price = $display > 0 ? $display : null;
            if (empty($existing->uom) && ! empty($line->uom)) {
                $existing->uom = $line->uom;
            }
        }

        return collect(array_values($folded));
    }
}
