<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centrix operational list search (products, sales, customers).
 *
 * Apply only after the caller has scoped the query to the authenticated tenant:
 * organization_id, branch limits, and (for sales) CentrixSalesScope::excludeLegacyMaterialized().
 *
 * Does not query LightStores legacy archive databases — use legacy archive APIs for that data.
 * Uses substring matching (%term%) on Centrix tables; user-supplied % and _ are escaped.
 * Multi-word product queries require every token to match (AND), tokens may appear in any order.
 * Money-like terms also match exact unit_price (e.g. 6300, 6,300.00).
 */
class SqlLikeSearch
{
    /** @var bool|null Cached for the request: ngram FULLTEXT index present + enabled. */
    protected static ?bool $productNameFulltext = null;

    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Strip MySQL FULLTEXT boolean operators from a user token.
     */
    public static function escapeFulltextBooleanToken(string $token): string
    {
        $clean = preg_replace('/[+\-><()~*"@]+/', ' ', $token) ?? $token;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return $clean;
    }

    /**
     * Whether product name search may use MySQL ngram FULLTEXT (pg_trgm equivalent).
     * Disabled in unit tests via PRODUCT_SEARCH_FULLTEXT=false unless overridden.
     */
    public static function canUseProductNameFulltext(): bool
    {
        if (self::$productNameFulltext !== null) {
            return self::$productNameFulltext;
        }

        if (! filter_var(env('PRODUCT_SEARCH_FULLTEXT', true), FILTER_VALIDATE_BOOLEAN)) {
            return self::$productNameFulltext = false;
        }

        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'mysql') {
                return self::$productNameFulltext = false;
            }
            if (! Schema::hasTable('products')) {
                return self::$productNameFulltext = false;
            }
            $indexes = collect(Schema::getIndexes('products'))->pluck('name')->all();

            return self::$productNameFulltext = in_array('products_product_name_ngram', $indexes, true);
        } catch (\Throwable) {
            return self::$productNameFulltext = false;
        }
    }

    /** @internal tests */
    public static function resetProductNameFulltextCache(): void
    {
        self::$productNameFulltext = null;
    }

    /** @internal tests */
    public static function forceProductNameFulltext(?bool $enabled): void
    {
        self::$productNameFulltext = $enabled;
    }

    /**
     * @return list<string>
     */
    public static function tokenize(string $term): array
    {
        $parts = preg_split('/[\s,;|\/]+/', trim($term)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Collapse spaces / punctuation the way POS client search does ("post man" ↔ "postman").
     */
    public static function compactSearchNeedle(string $term): string
    {
        $compact = preg_replace('/[\s\-_\/.]+/u', '', mb_strtolower(trim($term))) ?? '';

        return $compact;
    }

    /**
     * SQL expression that strips spaces / hyphens / underscores from a column (MySQL / SQLite).
     */
    protected static function compactColumnSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(LOWER({$column}), ' ', ''), '-', ''), '_', '')";
    }

    /**
     * Match product_name via ngram FULLTEXT when available, else LIKE contains.
     * Also matches spacing-insensitive compact form so "postman" finds "Post Man".
     *
     * @param  EloquentBuilder<mixed>|QueryBuilder  $inner
     */
    protected static function orWhereProductName(
        EloquentBuilder|QueryBuilder $inner,
        string $nameColumn,
        string $token,
        string $containsLike,
    ): void {
        $ftToken = self::escapeFulltextBooleanToken($token);
        $compact = self::compactSearchNeedle($token);
        $compactLike = $compact !== '' && mb_strlen($compact) >= 3
            ? '%'.self::escape($compact).'%'
            : null;

        if (self::canUseProductNameFulltext() && mb_strlen($ftToken) >= 2) {
            // BOOLEAN MODE + ngram: substring-friendly; keep LIKE as OR for safety on odd tokens.
            $inner->orWhere(function ($name) use ($nameColumn, $ftToken, $containsLike, $compactLike) {
                $name->whereRaw('MATCH('.$nameColumn.') AGAINST(? IN BOOLEAN MODE)', [$ftToken])
                    ->orWhere($nameColumn, 'like', $containsLike);
                if ($compactLike !== null) {
                    $name->orWhereRaw(self::compactColumnSql($nameColumn).' LIKE ?', [$compactLike]);
                }
            });

            return;
        }

        $inner->orWhere(function ($name) use ($nameColumn, $containsLike, $compactLike) {
            $name->where($nameColumn, 'like', $containsLike);
            if ($compactLike !== null) {
                $name->orWhereRaw(self::compactColumnSql($nameColumn).' LIKE ?', [$compactLike]);
            }
        });
    }

    /**
     * Spacing-insensitive match on product_code / shelf (postman ↔ post-man).
     *
     * @param  EloquentBuilder<mixed>|QueryBuilder  $inner
     */
    protected static function orWhereCompactColumn(
        EloquentBuilder|QueryBuilder $inner,
        string $column,
        string $token,
    ): void {
        $compact = self::compactSearchNeedle($token);
        if ($compact === '' || mb_strlen($compact) < 3) {
            return;
        }
        $inner->orWhereRaw(
            self::compactColumnSql($column).' LIKE ?',
            ['%'.self::escape($compact).'%'],
        );
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
        ?string $shelfColumn = 'products.shelf_location',
        string $priceColumn = 'products.unit_price',
    ): ?string {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

        $tokens = self::tokenize($term);
        if ($tokens === []) {
            return null;
        }

        // Money-like terms (6,300 / KES 2500): keep as one token so commas are not split.
        if (self::parseAmountSearchTerm($term) !== null) {
            $tokens = [$term];
        }

        // Pure numeric barcodes: skip expensive name %term% scans.
        $strongBarcode = (bool) preg_match('/^\d{6,}$/', $term);
        if ($strongBarcode) {
            $escaped = self::escape($term);
            $query->where(function ($inner) use ($term, $escaped, $codeColumn) {
                $inner->where($codeColumn, '=', $term)
                    ->orWhere($codeColumn, 'like', $escaped.'%');
            });

            return 'code_like';
        }

        // Multi-token: every token must match code, name, shelf, or exact unit price (any order).
        if (count($tokens) > 1) {
            foreach ($tokens as $token) {
                $escaped = self::escape($token);
                $contains = '%'.$escaped.'%';
                $amount = self::parseAmountSearchTerm($token);
                $query->where(function ($inner) use ($contains, $codeColumn, $nameColumn, $shelfColumn, $priceColumn, $amount, $token) {
                    $inner->where($codeColumn, 'like', $contains);
                    self::orWhereCompactColumn($inner, $codeColumn, $token);
                    self::orWhereProductName($inner, $nameColumn, $token, $contains);
                    if ($shelfColumn) {
                        $inner->orWhere($shelfColumn, 'like', $contains);
                        self::orWhereCompactColumn($inner, $shelfColumn, $token);
                    }
                    self::orWhereProductUnitPrice($inner, $priceColumn, $amount);
                });
            }

            return 'name';
        }

        $escaped = self::escape($term);
        $prefix = $escaped.'%';
        $contains = '%'.$escaped.'%';
        $amount = self::parseAmountSearchTerm($term);

        // Code-like terms: prefer exact/prefix on product_code (index-friendly).
        $looksLikeCode = (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9\\-_\\/.]*$/', $term)
            && strlen($term) <= 64;

        $query->where(function ($inner) use ($term, $prefix, $contains, $codeColumn, $nameColumn, $shelfColumn, $priceColumn, $looksLikeCode, $amount) {
            if ($looksLikeCode) {
                $inner->where($codeColumn, '=', $term)
                    ->orWhere($codeColumn, 'like', $prefix)
                    // Mid-string name/code (e.g. "unia" → "Gunia", partial SKUs).
                    ->orWhere($codeColumn, 'like', $contains);
                self::orWhereCompactColumn($inner, $codeColumn, $term);
                self::orWhereProductName($inner, $nameColumn, $term, $contains);
                if ($shelfColumn) {
                    $inner->orWhere($shelfColumn, 'like', $contains);
                    self::orWhereCompactColumn($inner, $shelfColumn, $term);
                }
                self::orWhereProductUnitPrice($inner, $priceColumn, $amount);

                return;
            }

            $inner->where($codeColumn, 'like', $contains);
            self::orWhereCompactColumn($inner, $codeColumn, $term);
            self::orWhereProductName($inner, $nameColumn, $term, $contains);
            if ($shelfColumn) {
                $inner->orWhere($shelfColumn, 'like', $contains);
                self::orWhereCompactColumn($inner, $shelfColumn, $term);
            }
            self::orWhereProductUnitPrice($inner, $priceColumn, $amount);
        });

        return $looksLikeCode ? 'code_like' : 'name';
    }

    /**
     * Exact unit-price match for money-like search tokens (e.g. 6300, 6,300.00, KES 2500).
     *
     * @param  EloquentBuilder<mixed>|QueryBuilder  $inner
     */
    protected static function orWhereProductUnitPrice(
        EloquentBuilder|QueryBuilder $inner,
        string $priceColumn,
        ?float $amount,
    ): void {
        if ($amount === null) {
            return;
        }

        $inner->orWhereRaw('ROUND('.$priceColumn.', 2) = ?', [$amount]);
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

        // Offline POS sync recovery: find the prior upload by client uuid / pos_sync_id
        // so an edit-before-sync supersedes instead of posting a second live sale.
        $isClientSaleUuid = (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $term,
        );
        $isPrevEditUuid = str_starts_with(strtolower($term), 'prev-edit-');
        if ($isClientSaleUuid || $isPrevEditUuid) {
            $query->where(function ($sub) use ($term) {
                $sub->where('sales.fulfillment_meta->client_sale_uuid', $term)
                    ->orWhere('sales.fulfillment_meta->offline_client_sale_uuid', $term)
                    ->orWhere('sales.fulfillment_meta->pos_sync_id', $term)
                    ->orWhere('sales.fulfillment_meta->pos_sync_id', 'like', $term.':%');
            });

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
