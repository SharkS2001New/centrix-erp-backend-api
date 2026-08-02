<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Centrix operational list search (products, sales, customers).
 *
 * Apply only after the caller has scoped the query to the authenticated tenant:
 * organization_id, branch limits, and (for sales) CentrixSalesScope::excludeLegacyMaterialized().
 *
 * Does not query LightStores legacy archive databases — use legacy archive APIs for that data.
 * Uses substring matching (%term%) on Centrix tables; user-supplied % and _ are escaped.
 */
class SqlLikeSearch
{
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     * @return 'exact_code'|'code_like'|'name'|null  Match mode (for relevance ordering).
     */
    public static function applyProductSearch(
        EloquentBuilder|QueryBuilder $query,
        string $term,
        string $codeColumn = 'products.product_code',
        string $nameColumn = 'products.product_name',
    ): ?string {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

        $escaped = self::escape($term);
        $prefix = $escaped.'%';
        $contains = '%'.$escaped.'%';

        // Code-like terms: prefer exact/prefix on product_code (index-friendly).
        $looksLikeCode = (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9\\-_\\/.]*$/', $term)
            && strlen($term) <= 64;
        // Pure numeric barcodes: skip expensive name %term% scans.
        $strongBarcode = $looksLikeCode && (bool) preg_match('/^\d{6,}$/', $term);

        $query->where(function ($inner) use ($term, $prefix, $contains, $codeColumn, $nameColumn, $looksLikeCode, $strongBarcode) {
            if ($looksLikeCode) {
                $inner->where($codeColumn, '=', $term)
                    ->orWhere($codeColumn, 'like', $prefix);
                if (! $strongBarcode) {
                    $inner->orWhere($nameColumn, 'like', $contains);
                }

                return;
            }

            $inner->where($nameColumn, 'like', $contains)
                ->orWhere($codeColumn, 'like', $contains);
        });

        return $looksLikeCode ? 'code_like' : 'name';
    }

    /**
     * When an exact product_code hit exists in the already-scoped query, restrict to that code only.
     *
     * @param  EloquentBuilder<mixed>  $query
     */
    public static function restrictToExactProductCodeIfPresent(
        EloquentBuilder $query,
        string $term,
        string $codeColumn = 'products.product_code',
    ): bool {
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        $exists = (clone $query)->where($codeColumn, $term)->limit(1)->exists();
        if (! $exists) {
            return false;
        }

        $query->where($codeColumn, $term);

        return true;
    }

    /**
     * Parse a money-like search term (1500, 1,500.00, KES 2500).
     */
    public static function parseAmountSearchTerm(string $term): ?float
    {
        $trimmed = trim($term);
        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^(?:kes|ksh|sh|usd|\$)?\s*[\d,]+(?:\.\d{1,2})?$/i', $trimmed)) {
            return null;
        }

        $normalized = preg_replace('/[^\d.]/', '', str_replace(',', '', $trimmed));
        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    /**
     * Global sales / mobile-orders list search: order #, customer, phone, amount, and line products.
     *
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applySalesOrderSearch(
        EloquentBuilder|QueryBuilder $query,
        string $term,
        bool $includeCustomerRelation = false,
    ): void {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        if (preg_match('/^#?S0*(\d+)$/i', $term, $matches)) {
            $query->where('sales.order_num', (int) $matches[1]);

            return;
        }

        $escaped = self::escape($term);
        $like = '%'.$escaped.'%';
        $prefix = $escaped.'%';
        $isDigits = ctype_digit($term);
        $amount = self::parseAmountSearchTerm($term);
        $isEloquent = $query instanceof EloquentBuilder;

        $query->where(function ($sub) use (
            $term,
            $like,
            $prefix,
            $isDigits,
            $amount,
            $includeCustomerRelation,
            $isEloquent,
        ) {
            if ($isDigits) {
                $orderNum = (int) $term;
                $sub->where('sales.order_num', $orderNum)
                    ->orWhere('sales.pos_order_num', $orderNum)
                    ->orWhere('sales.customer_num', $orderNum)
                    ->orWhere('sales.order_num', 'like', $prefix);
            } else {
                $sub->where('sales.customer_name_override', 'like', $like)
                    ->orWhereRaw('CAST(sales.order_num AS CHAR) LIKE ?', [$like])
                    ->orWhereRaw('CAST(sales.pos_order_num AS CHAR) LIKE ?', [$like])
                    ->orWhereRaw('CAST(sales.customer_num AS CHAR) LIKE ?', [$like]);
            }

            if ($amount !== null) {
                $sub->orWhereRaw('ROUND(sales.order_total, 2) = ?', [$amount])
                    ->orWhereRaw('ROUND(COALESCE(sales.amount_paid, 0), 2) = ?', [$amount]);
            }

            if ($includeCustomerRelation && $isEloquent) {
                $sub->orWhereHas('customer', function ($customer) use ($like, $isDigits, $term) {
                    if ($isDigits) {
                        $customer->where('customer_num', (int) $term)
                            ->orWhere('phone_number', 'like', $like)
                            ->orWhere('additional_phone', 'like', $like);

                        return;
                    }

                    $customer->where('customer_name', 'like', $like)
                        ->orWhere('phone_number', 'like', $like)
                        ->orWhere('additional_phone', 'like', $like)
                        ->orWhere('kra_pin', 'like', $like)
                        ->orWhere('town', 'like', $like);
                });
            }

            // Line products: name / code — find which customers bought an item.
            if ($isEloquent) {
                $sub->orWhereHas('items', function ($items) use ($like) {
                    $items->where('product_code', 'like', $like)
                        ->orWhere('item_code', 'like', $like)
                        ->orWhereHas('product', function ($product) use ($like) {
                            $product->where('product_name', 'like', $like)
                                ->orWhere('product_code', 'like', $like);
                        });
                });
            }
        });
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applyCustomerSearch(EloquentBuilder|QueryBuilder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.self::escape($term).'%';

        $query->where(function ($inner) use ($term, $like) {
            if (ctype_digit($term)) {
                $inner->where('customer_num', (int) $term)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('additional_phone', 'like', $like);

                return;
            }

            $inner->where('customer_name', 'like', $like)
                ->orWhere('phone_number', 'like', $like)
                ->orWhere('additional_phone', 'like', $like)
                ->orWhere('town', 'like', $like)
                ->orWhere('kra_pin', 'like', $like);

            if (preg_match('/^\d+$/', $term)) {
                $inner->orWhere('customer_num', (int) $term);
            }
        });
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     * @param  list<string>  $columns
     */
    public static function applyOrColumnsSearch(
        EloquentBuilder|QueryBuilder $query,
        string $term,
        array $columns,
    ): void {
        $term = trim($term);
        if ($term === '' || $columns === []) {
            return;
        }

        $like = '%'.self::escape($term).'%';

        $query->where(function ($inner) use ($like, $columns) {
            foreach ($columns as $i => $col) {
                $method = $i === 0 ? 'where' : 'orWhere';
                $inner->{$method}($col, 'like', $like);
            }
        });
    }
}
