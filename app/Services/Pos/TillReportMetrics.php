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
 *   ORDTTL  = SUM(order_total) on fully paid POS sales
 *           + SUM(amount_paid) on genuine credit partials for the session
 *             (never full order_total of the unpaid balance)
 *   DBTTL   = debtor / invoice collections taken on the session (debtor_payments)
 *   FLOATTTL = session working float
 *   EXPTTL  = session expenses
 *
 * Genuine credit partial tenders:
 *   - Count amount_paid / sale_payments in Cash/M-Pesa lines and ORDTTL.
 *   - Do NOT add the unpaid balance (order_total − amount_paid) into ORDTTL.
 *   - Fake non-credit shortfalls must not exist (checkout forces full settle);
 *     tender filters still require fully paid OR is_credit_sale.
 *   - Amount maths only — never the denormalized payment_status label.
 *
 * New credit invoices (legacy INVOICETOTALS) are reported separately and are not
 * added into netsales / totalsales — same as the stored procedures.
 */
class TillReportMetrics
{
    /** Minimum amount treated as collected tender (avoids float noise). */
    public const MIN_COLLECTED = 0.009;

    /**
     * Restrict a sales query to fully paid / collected POS orders (legacy order_masters).
     * Use for the fully-paid portion of ORDTTL / gross. Pure unpaid credit stays out.
     * Amount maths only — never the denormalized payment_status label.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyCollectedSalesFilter($query, string $alias = ''): void
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.').'.';
        $paid = "{$prefix}amount_paid";
        $total = "{$prefix}order_total";
        $query->whereRaw("COALESCE({$paid}, 0) > ?", [self::MIN_COLLECTED]);
        $query->whereRaw(
            "COALESCE({$paid}, 0) + ? >= COALESCE({$total}, 0)",
            [self::MIN_COLLECTED],
        );
    }

    /**
     * Sales that took any tender on this session — fully paid, or genuine credit partial.
     * Use for cash drawer / payment-method mix. Fake non-credit underpayments are excluded.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applySessionTenderSalesFilter($query, string $alias = ''): void
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.').'.';
        $statusCol = "{$prefix}status";
        $paid = "{$prefix}amount_paid";
        $total = "{$prefix}order_total";
        $credit = "{$prefix}is_credit_sale";
        $query->whereNotIn($statusCol, ['cancelled', 'expired', 'held', 'draft']);
        $query->whereRaw("COALESCE({$paid}, 0) > ?", [self::MIN_COLLECTED]);
        $query->where(function ($inner) use ($paid, $total, $credit) {
            $inner->whereRaw(
                "COALESCE({$paid}, 0) + ? >= COALESCE({$total}, 0)",
                [self::MIN_COLLECTED],
            )->orWhere($credit, 1);
        });
    }

    /**
     * Credit sales with a partial tender (amount paid, balance still outstanding).
     * Used to add SUM(amount_paid) into ORDTTL without the unpaid balance.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyCreditPartialSalesFilter($query, string $alias = ''): void
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.').'.';
        $paid = "{$prefix}amount_paid";
        $total = "{$prefix}order_total";
        $credit = "{$prefix}is_credit_sale";
        $query->where($credit, 1);
        $query->whereRaw("COALESCE({$paid}, 0) > ?", [self::MIN_COLLECTED]);
        $query->whereRaw(
            "COALESCE({$paid}, 0) + ? < COALESCE({$total}, 0)",
            [self::MIN_COLLECTED],
        );
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
