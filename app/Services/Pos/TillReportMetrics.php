<?php

namespace App\Services\Pos;

use Illuminate\Database\Query\Builder;

/**
 * Shared till X / Z / EOD maths helpers.
 *
 * Matches legacy POS xreport_summary / zreport_summary:
 *   netsales   = ORDTTL + DBTTL + FLOATTTL − EXPTTL
 *   totalsales = ORDTTL + DBTTL + FLOATTTL
 *
 * Centrix mapping:
 *   ORDTTL  = SUM(order_total) on **fully paid non-credit** POS sales for the till
 *             session (order_total already reflects edit top-ups / returns — do not
 *             add adjustment amounts or subtract returns again)
 *   DBTTL   = debtor / invoice collections taken on the session (sale_payments on
 *             credit sales, or on sales from another / no till session)
 *   FLOATTTL = session working float
 *   EXPTTL  = session expenses
 *
 * Genuine partial / credit-input tenders:
 *   - Do NOT add full order_total into ORDTTL (that would inflate Z).
 *   - DO count cash/M-Pesa/etc. actually taken on this cashier session
 *     (sale_payments / amount_paid for that float_session_id).
 *   - Outstanding balance stays on credit outstanding / All Orders partial —
 *     scoped by cashier / session filters, never via a stale payment_status label.
 *
 * Credit invoices (legacy INVOICETOTALS / DBTTL):
 *   - Fully paid credit sales must NOT enter ORDTTL — collections belong in DBTTL
 *     so X / Z / End of Day show "Invoice sales (paid debtors)" even when the
 *     credit sale and the collection share the same till session.
 *   - New unpaid credit invoices are display-only and are not added into
 *     netsales / totalsales — same as the stored procedures.
 */
class TillReportMetrics
{
    /** Minimum amount treated as collected tender (avoids float noise). */
    public const MIN_COLLECTED = 0.009;

    /**
     * Restrict a sales query to fully paid / collected POS orders (legacy order_masters).
     * Use for ORDTTL / gross sales only. Pure unpaid credit and partial tenders stay out —
     * they must not inflate X / Z / EOD totalsales with the full order_total.
     * Amount maths only — never the denormalized payment_status label.
     *
     * Credit sales are excluded from ORDTTL when $excludeCreditSales is true (till maths):
     * their collections are counted as DBTTL instead.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyCollectedSalesFilter($query, string $alias = '', bool $excludeCreditSales = false): void
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.').'.';
        $paid = "{$prefix}amount_paid";
        $total = "{$prefix}order_total";
        $query->whereRaw("COALESCE({$paid}, 0) > ?", [self::MIN_COLLECTED]);
        $query->whereRaw(
            "COALESCE({$paid}, 0) + ? >= COALESCE({$total}, 0)",
            [self::MIN_COLLECTED],
        );
        if ($excludeCreditSales) {
            $query->where(function ($q) use ($prefix) {
                $q->where("{$prefix}is_credit_sale", 0)
                    ->orWhereNull("{$prefix}is_credit_sale");
            });
        }
    }

    /**
     * ORDTTL filter for X / Z / End of Day till maths — fully paid cash sales only.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyOrdTtlSalesFilter($query, string $alias = ''): void
    {
        $this->applyCollectedSalesFilter($query, $alias, true);
    }

    /**
     * DBTTL predicate: payments collected on a till session that settle credit /
     * invoice debt (including same-session credit collections).
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyDebtorCollectionFilter($query, string $saleAlias = 's', string $paymentAlias = 'sp'): void
    {
        $s = rtrim($saleAlias, '.').'.';
        $sp = rtrim($paymentAlias, '.').'.';
        $query->where(function ($q) use ($s, $sp) {
            $q->where("{$s}is_credit_sale", 1)
                ->orWhereNull("{$s}float_session_id")
                ->orWhereColumn("{$s}float_session_id", '!=', "{$sp}float_session_id");
        });
    }

    /**
     * Sales that took any tender on this session — including genuine partial / credit input.
     * Use for cash drawer / payment-method mix. Not for ORDTTL.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applySessionTenderSalesFilter($query, string $alias = ''): void
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.').'.';
        $statusCol = "{$prefix}status";
        $paid = "{$prefix}amount_paid";
        $query->whereNotIn($statusCol, ['cancelled', 'expired', 'held', 'draft']);
        $query->whereRaw("COALESCE({$paid}, 0) > ?", [self::MIN_COLLECTED]);
    }

    /** Raw SQL predicate for fully collected sales (alias optional, e.g. "s."). */
    public function collectedSalesSql(string $aliasPrefix = ''): string
    {
        $prefix = $aliasPrefix === '' ? '' : rtrim($aliasPrefix, '.').'.';
        $paid = "{$prefix}amount_paid";
        $total = "{$prefix}order_total";

        return 'COALESCE('.$paid.', 0) > '.self::MIN_COLLECTED
            .' AND COALESCE('.$paid.', 0) + '.self::MIN_COLLECTED.' >= COALESCE('.$total.', 0)';
    }

    /**
     * Outstanding credit booked on sales (legacy INVOICETOTALS analogue).
     * Display only — never mixed into netsales / totalsales.
     */
    public function creditOutstandingSql(string $aliasPrefix = ''): string
    {
        $prefix = $aliasPrefix === '' ? '' : rtrim($aliasPrefix, '.').'.';

        return "COALESCE(SUM(CASE WHEN {$prefix}is_credit_sale = 1"
            ." THEN GREATEST(COALESCE({$prefix}order_total, 0) - COALESCE({$prefix}amount_paid, 0), 0)"
            .' ELSE 0 END), 0)';
    }

    /**
     * Legacy netsales / expected closing:
     * ORDTTL + DBTTL + FLOATTTL − EXPTTL (± optional cash movements).
     */
    public function expectedClosing(
        float $ordTtl,
        float $dbTtl,
        float $floatTtl,
        float $expTtl,
        float $cashMovementsOut = 0.0,
        float $cashMovementsIn = 0.0,
    ): float {
        return round(
            $ordTtl + $dbTtl + $floatTtl - $expTtl - $cashMovementsOut + $cashMovementsIn,
            2,
        );
    }

    /** Legacy totalsales: ORDTTL + DBTTL + FLOATTTL. */
    public function totalSales(float $ordTtl, float $dbTtl, float $floatTtl): float
    {
        return round($ordTtl + $dbTtl + $floatTtl, 2);
    }
}
