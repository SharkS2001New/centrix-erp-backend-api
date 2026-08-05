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
 *   ORDTTL  = SUM(order_total) on paid POS sales for the till session
 *             (order_total already reflects edit top-ups / returns — do not
 *             add adjustment amounts or subtract returns again)
 *   DBTTL   = debtor / invoice collections taken on the session (debtor_payments)
 *   FLOATTTL = session working float
 *   EXPTTL  = session expenses
 *
 * New credit invoices (legacy INVOICETOTALS) are reported separately and are not
 * added into netsales / totalsales — same as the stored procedures.
 */
class TillReportMetrics
{
    /** Minimum amount treated as collected tender (avoids float noise). */
    public const MIN_COLLECTED = 0.009;

    /**
     * Restrict a sales query to paid POS orders (legacy order_masters).
     * Pure unpaid credit stays out of ORDTTL (it lived on debtor_masters).
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyCollectedSalesFilter($query, string $alias = ''): void
    {
        $col = $alias === '' ? 'amount_paid' : "{$alias}.amount_paid";
        $query->whereRaw("COALESCE({$col}, 0) > ?", [self::MIN_COLLECTED]);
    }

    /** Raw SQL predicate for collected sales (alias optional, e.g. "s."). */
    public function collectedSalesSql(string $aliasPrefix = ''): string
    {
        $col = $aliasPrefix === '' ? 'amount_paid' : rtrim($aliasPrefix, '.').'.amount_paid';

        return 'COALESCE('.$col.', 0) > '.self::MIN_COLLECTED;
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
