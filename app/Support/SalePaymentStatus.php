<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Canonical sale payment buckets for lists, summary cards, and till maths.
 *
 * Source of truth is always amount_paid vs order_total. The denormalized
 * payment_status column is a cache that must be rewritten from amounts — offline
 * sync / merges / cancels can leave it stale (e.g. "partial" with full tenders).
 *
 * Genuine partials (credit input / partial tender) belong in the partial bucket and
 * on that cashier's sale + till session tender mix. They must not inflate ORDTTL
 * with the full order_total — only the tender taken counts toward the drawer.
 */
class SalePaymentStatus
{
    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    /** Legacy alias written by SaleMergeService — treat as partial everywhere. */
    public const PARTIALLY_PAID_ALIAS = 'partially_paid';

    public const EPSILON = 0.01;

    /**
     * @return self::UNPAID|self::PARTIAL|self::PAID
     */
    public static function derive(float $orderTotal, float $amountPaid): string
    {
        if ($orderTotal <= self::EPSILON) {
            return self::PAID;
        }
        if ($amountPaid <= self::EPSILON) {
            return self::UNPAID;
        }
        if ($amountPaid + self::EPSILON >= $orderTotal) {
            return self::PAID;
        }

        return self::PARTIAL;
    }

    /**
     * Resolve the display / stored bucket for a sale row.
     * Cancelled and expired never stay partial/paid on the label.
     *
     * @return self::UNPAID|self::PARTIAL|self::PAID
     */
    public static function resolve(?string $workflowStatus, float $orderTotal, float $amountPaid): string
    {
        $status = strtolower(trim((string) $workflowStatus));
        if (in_array($status, ['cancelled', 'expired'], true)) {
            return self::UNPAID;
        }

        return self::derive($orderTotal, $amountPaid);
    }

    /**
     * Keep the denormalized payment_status column aligned with amounts.
     * Call from SaleObserver::saving so every write path stays truthful.
     */
    public static function syncModel(\App\Models\Sale $sale): void
    {
        $derived = self::resolve(
            (string) ($sale->status ?? ''),
            (float) ($sale->order_total ?? 0),
            (float) ($sale->amount_paid ?? 0),
        );
        if ((string) ($sale->payment_status ?? '') !== $derived) {
            $sale->payment_status = $derived;
        }
    }

    public static function normalizeLabel(?string $paymentStatus): string
    {
        $key = strtolower(trim((string) $paymentStatus));
        if ($key === self::PARTIALLY_PAID_ALIAS || $key === 'partial_paid') {
            return self::PARTIAL;
        }
        if (in_array($key, [self::UNPAID, self::PARTIAL, self::PAID], true)) {
            return $key;
        }

        return self::UNPAID;
    }

    /** SQL: active (not cancelled/expired) sale. */
    public static function activeStatusSql(string $prefix = 'sales.'): string
    {
        return "{$prefix}status NOT IN ('cancelled', 'expired')";
    }

    /** SQL boolean: fully paid by amounts. */
    public static function isPaidSql(string $prefix = 'sales.'): string
    {
        $eps = self::EPSILON;

        return "(COALESCE({$prefix}order_total, 0) <= {$eps}"
            ." OR COALESCE({$prefix}amount_paid, 0) + {$eps} >= COALESCE({$prefix}order_total, 0))";
    }

    /** SQL boolean: nothing collected yet. */
    public static function isUnpaidSql(string $prefix = 'sales.'): string
    {
        $eps = self::EPSILON;

        return "(COALESCE({$prefix}order_total, 0) > {$eps}"
           ." AND COALESCE({$prefix}amount_paid, 0) <= {$eps})";
    }

    /** SQL boolean: some tender, balance remaining. */
    public static function isPartialSql(string $prefix = 'sales.'): string
    {
        $eps = self::EPSILON;

        return "(COALESCE({$prefix}amount_paid, 0) > {$eps}"
           ." AND COALESCE({$prefix}amount_paid, 0) + {$eps} < COALESCE({$prefix}order_total, 0))";
    }

    /**
     * Restrict a list query to unpaid / partial / paid using amount maths.
     * Also accepts legacy partially_paid filter value.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function applyListFilter($query, string $paymentStatus): void
    {
        $bucket = self::normalizeLabel($paymentStatus);
        $query->whereRaw(self::activeStatusSql('sales.'));
        // Cancelled / expired never belong on unpaid/partial collection queues.
        // Do not exclude workflow "completed"/"paid" — POS can leave those labels
        // while amount_paid is still short (that is exactly what these queues must surface).
        if ($bucket === self::UNPAID || $bucket === self::PARTIAL) {
            $query->whereNotIn('sales.status', ['cancelled', 'expired']);
        }

        match ($bucket) {
            self::PAID => $query->whereRaw(self::isPaidSql('sales.')),
            self::PARTIAL => $query->whereRaw(self::isPartialSql('sales.')),
            default => $query->whereRaw(self::isUnpaidSql('sales.')),
        };
    }
}
