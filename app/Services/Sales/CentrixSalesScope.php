<?php

namespace App\Services\Sales;

use App\Support\EffectiveSaleDate;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Centrix operational sales metrics exclude materialized legacy imports.
 * Those rows exist only to support KRA legacy returns / credit notes.
 */
class CentrixSalesScope
{
    /**
     * Order statuses included in backoffice sales performance reports.
     * Excludes draft/held/cancelled/expired so reports can trace work in progress
     * (booked → completed, including unpaid / partial payments).
     *
     * @return list<string>
     */
    public static function reportPipelineStatuses(): array
    {
        return [
            'booked',
            'pending',
            'unpaid',
            'pending_payment',
            'paid',
            'processed',
            'delivered',
            'completed',
        ];
    }

    /** SQL: `s.status IN ('booked', …)` */
    public static function reportPipelineStatusSql(string $column = 's.status'): string
    {
        $list = implode(', ', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            self::reportPipelineStatuses(),
        ));

        return "{$column} IN ({$list})";
    }

    /**
     * Report date bucket: order placed / booked date.
     * Prefer effective_sale_date (same as Sales Orders "Placed date" filter) when present;
     * otherwise DATE(created_at). Never completed_at — completing later must not move the day.
     */
    public static function reportSaleDateSql(string $alias = 's'): string
    {
        if (EffectiveSaleDate::columnExists()) {
            return "{$alias}.effective_sale_date";
        }

        return "DATE({$alias}.created_at)";
    }

    /**
     * Distinct customers served — registered accounts, named walk-ins, and one count
     * per anonymous walk-in transaction (POS orders without customer_num).
     */
    public static function metricCustomerCountSql(string $alias = 's'): string
    {
        $customerNum = "{$alias}.customer_num";
        $nameOverride = "{$alias}.customer_name_override";
        $saleId = "{$alias}.id";

        return "COUNT(DISTINCT CASE
            WHEN {$customerNum} IS NOT NULL THEN CONCAT('reg:', {$customerNum})
            WHEN NULLIF(TRIM({$nameOverride}), '') IS NOT NULL
                AND LOWER(TRIM({$nameOverride})) NOT IN ('walk-in', 'walk in', 'walkin')
                THEN CONCAT('walk:', LOWER(TRIM({$nameOverride})))
            ELSE CONCAT('sale:', {$saleId})
        END)";
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    public static function excludeLegacyMaterialized(EloquentBuilder|QueryBuilder $query, ?string $tableAlias = null): EloquentBuilder|QueryBuilder
    {
        // Resolves via the VIRTUAL INVISIBLE `legacy_materialized` column
        // added in 2026_06_19_000001, indexed by 2026_11_07_000006. The
        // generated-column CASE expression matches CentrixSalesScope::isLegacyImportFlag()
        // exactly — PHP true / 1 / "1" / "true" count as legacy; everything
        // else is live. Replaces the previous unindexable JSON predicate.
        $column = $tableAlias ? "{$tableAlias}.legacy_materialized" : 'legacy_materialized';

        return $query->where($column, 0);
    }

    /** SQL predicate for MySQL views (e.g. alias `s`). */
    public static function legacyExcludeSql(string $alias = 's'): string
    {
        return "{$alias}.legacy_materialized = 0";
    }

    /**
     * Canonical flag-value check for fulfillment_meta.legacy_import. Mirrors
     * the generated `sales.legacy_materialized` column's CASE expression
     * exactly: only PHP `true`, int `1`, string `"1"`, and string `"true"`
     * count as "legacy". Everything else — `false`, int `0`, int `2`,
     * `null`, missing key, arrays, objects, string `"TRUE"`, etc. — is
     * treated as live.
     *
     * Use this at every PHP read site that formerly cast `(bool) $meta['legacy_import']`.
     * The broad bool cast accepted int 2, string "TRUE", etc. as truthy —
     * SQL never would. Aligning here keeps PHP and MySQL in lockstep.
     */
    public static function isLegacyImportFlag(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true';
    }

    /**
     * Restrict a query builder to sales pipeline statuses used by reports.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @return EloquentBuilder<Model>|QueryBuilder
     */
    public static function whereReportPipelineStatus(EloquentBuilder|QueryBuilder $query, string $column = 'status'): EloquentBuilder|QueryBuilder
    {
        return $query->whereIn($column, self::reportPipelineStatuses());
    }

    /**
     * Subquery that totals raw line gross/VAT per sale for header allocation.
     *
     * Line reports must allocate `order_total` / `total_vat` across lines so
     * SUM(allocated) = SUM(headers). Raw `si.amount` ignores `order_discount`.
     *
     * Returns the parenthesized SELECT only (no alias) so callers can
     * `JOIN (... ) ls ON …` or `join(DB::raw('(...) as ls'), …)`.
     */
    public static function saleLineTotalsSubquerySql(): string
    {
        return <<<'SQL'
(
    SELECT
        sale_id,
        SUM(amount) AS line_gross,
        SUM(product_vat) AS line_vat
    FROM sale_items
    GROUP BY sale_id
)
SQL;
    }

    /** @deprecated use saleLineTotalsSubquerySql() */
    public static function saleLineTotalsJoinSql(string $alias = 'ls'): string
    {
        return self::saleLineTotalsSubquerySql().' '.$alias;
    }

    /**
     * Line share of header gross (VAT-inclusive payable after order discount).
     *
     * @param  string  $siAlias  sale_items alias
     * @param  string  $salesAlias  sales alias
     * @param  string  $lsAlias  line totals subquery alias from saleLineTotalsJoinSql()
     */
    public static function allocatedLineGrossSql(
        string $siAlias = 'si',
        string $salesAlias = 's',
        string $lsAlias = 'ls',
    ): string {
        return "CASE WHEN {$lsAlias}.line_gross > 0 THEN ({$siAlias}.amount * ({$salesAlias}.order_total / {$lsAlias}.line_gross)) ELSE 0 END";
    }

    /**
     * Line share of header VAT (matches sales.total_vat after allocation).
     */
    public static function allocatedLineVatSql(
        string $siAlias = 'si',
        string $salesAlias = 's',
        string $lsAlias = 'ls',
    ): string {
        return <<<SQL
CASE
    WHEN {$lsAlias}.line_vat > 0 THEN ({$siAlias}.product_vat * ({$salesAlias}.total_vat / {$lsAlias}.line_vat))
    WHEN {$lsAlias}.line_gross > 0 THEN ({$salesAlias}.total_vat * ({$siAlias}.amount / {$lsAlias}.line_gross))
    ELSE 0
END
SQL;
    }

    /**
     * Line discount + proportional share of order-level discount.
     */
    public static function allocatedLineDiscountSql(
        string $siAlias = 'si',
        string $salesAlias = 's',
        string $lsAlias = 'ls',
    ): string {
        return <<<SQL
(
    COALESCE({$siAlias}.discount_given, 0)
    + CASE
        WHEN {$lsAlias}.line_gross > 0 THEN ({$siAlias}.amount * (COALESCE({$salesAlias}.order_discount, 0) / {$lsAlias}.line_gross))
        ELSE 0
      END
)
SQL;
    }

    /**
     * Scale header VAT when order discount reduces payable gross.
     * Keeps net = order_total − total_vat consistent with inclusive pricing.
     */
    public static function scaleVatForOrderDiscount(float $lineGross, float $lineVat, float $orderDiscount): array
    {
        $lineGross = max(0, round($lineGross, 2));
        $lineVat = max(0, round($lineVat, 2));
        $orderDiscount = min(max(0, round($orderDiscount, 2)), $lineGross);
        $orderTotal = max(0, round($lineGross - $orderDiscount, 2));

        if ($lineGross <= 0 || $orderDiscount <= 0) {
            return [
                'order_total' => $orderTotal,
                'total_vat' => $lineVat,
                'order_discount' => $orderDiscount,
            ];
        }

        return [
            'order_total' => $orderTotal,
            'total_vat' => round($lineVat * ($orderTotal / $lineGross), 2),
            'order_discount' => $orderDiscount,
        ];
    }
}
