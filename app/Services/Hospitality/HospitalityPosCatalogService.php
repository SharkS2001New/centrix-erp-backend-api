<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityOutlet;
use App\Models\Organization;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogPricingBroadcastService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Support\SqlLikeSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HospitalityPosCatalogService
{
    public const POPULAR_DAYS_DEFAULT = 5;

    public function __construct(
        protected ProductCatalogScopeService $catalogScope,
    ) {}

    /**
     * Resolve Bar vs Hotel menu channel from an outlet.
     * bar → bar; restaurant/other → hotel.
     */
    public static function menuChannelForOutlet(?HospitalityOutlet $outlet): string
    {
        if (! $outlet) {
            return 'bar';
        }

        return strtolower((string) $outlet->outlet_type) === 'bar' ? 'bar' : 'hotel';
    }

    public function resolveOutletForUser(Organization $org, User $user, ?int $outletId = null): HospitalityOutlet
    {
        if ($outletId) {
            return HospitalityOutlet::query()
                ->where('organization_id', $org->id)
                ->where('id', $outletId)
                ->where('is_active', true)
                ->firstOrFail();
        }

        if (Schema::hasColumn('users', 'hospitality_outlet_id') && $user->hospitality_outlet_id) {
            $assigned = HospitalityOutlet::query()
                ->where('organization_id', $org->id)
                ->where('id', $user->hospitality_outlet_id)
                ->where('is_active', true)
                ->first();
            if ($assigned) {
                return $assigned;
            }
        }

        return app(HospitalityCheckService::class)->ensureDefaultOutlet(
            $org,
            $user->branch_id ? (int) $user->branch_id : null,
        );
    }

    public function assertProductAllowedForOutlet(Organization $org, HospitalityOutlet $outlet, string $productCode): void
    {
        $channel = self::menuChannelForOutlet($outlet);
        $column = $channel === 'bar' ? 'sell_on_bar' : 'sell_on_hotel';
        $product = Product::query()
            ->with('subCategory.category')
            ->where('organization_id', $org->id)
            ->where('product_code', $productCode)
            ->first();
        if (! $product) {
            throw ValidationException::withMessages(['product_code' => ['Product not found.']]);
        }
        $label = $channel === 'bar' ? 'Bar' : 'Restaurant';
        if (Schema::hasColumn('products', $column) && ! (bool) ($product->{$column} ?? true)) {
            throw ValidationException::withMessages([
                'product_code' => ["{$product->product_name} is not sellable on {$label} POS."],
            ]);
        }
        if ($channel === 'bar') {
            $categoryName = strtolower((string) ($product->subCategory?->category?->category_name ?? ''));
            if ($this->menuGroupForCategoryName($categoryName) === 'food') {
                throw ValidationException::withMessages([
                    'product_code' => ["{$product->product_name} is a food item and is not sellable on Bar POS."],
                ]);
            }
        }
    }

    /**
     * Update the menu selling price for a product on the cashier's Bar/Hotel channel.
     *
     * @return array{product_code: string, product_name: string, unit_price: float}
     */
    public function updateMenuPrice(
        Organization $org,
        User $user,
        Request $request,
        string $productCode,
        float $unitPrice,
        ?int $outletId = null,
    ): array {
        $outlet = $this->resolveOutletForUser($org, $user, $outletId);
        $this->assertProductAllowedForOutlet($org, $outlet, $productCode);

        $query = Product::query()
            ->where('organization_id', $org->id)
            ->where('product_code', $productCode);
        $this->catalogScope->scopeForUser($query, $user, $request);
        $product = $query->first();
        if (! $product) {
            throw ValidationException::withMessages(['product_code' => ['Product not found.']]);
        }

        $nextPrice = round(max(0, $unitPrice), 2);
        $prevUnit = round((float) $product->unit_price, 2);
        $product->unit_price = $nextPrice;
        if (Schema::hasColumn('products', 'last_selling_price')) {
            $product->last_selling_price = $nextPrice;
        }
        if ($user->id) {
            $product->updated_by = $user->id;
        }
        $product->save();
        $product->refresh();

        if ($nextPrice !== $prevUnit) {
            if (Schema::hasTable('price_history')) {
                PriceHistory::create([
                    'product_code' => $product->product_code,
                    'unit_price' => $nextPrice,
                    'cost_price' => (float) ($product->last_cost_price ?? 0),
                    'discount_pct' => (float) ($product->discount_percentage ?? 0),
                    'changed_by' => $user->id,
                    'organization_id' => $product->organization_id ?? $org->id,
                    'changed_at' => now(),
                ]);
            }
            app(CatalogPricingBroadcastService::class)->notifyProductPriceChanged(
                (int) $org->id,
                (string) $product->product_code,
                (string) $product->product_name,
                $user->id,
            );
        }

        return [
            'product_code' => (string) $product->product_code,
            'product_name' => (string) $product->product_name,
            'unit_price' => round((float) $product->unit_price, 2),
        ];
    }

    /**
     * Active products for hotel POS: most-sold (recent window) first, then name.
     * Filtered by cashier outlet channel (Bar vs Hotel).
     *
     * @return array<string, mixed>
     */
    public function catalog(Organization $org, User $user, Request $request): array
    {
        $days = max(1, min(90, (int) $request->input('popular_days', self::POPULAR_DAYS_DEFAULT)));
        $settings = HospitalityPosSettings::forOrganization($org);
        $pageSize = $settings['hotel_pos_catalog_limit'];
        $perPage = min(100, max(1, (int) $request->input('per_page', $pageSize)));
        $offset = max(0, (int) $request->input('offset', 0));
        $search = trim((string) $request->input('q', ''));

        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;
        $outlet = $this->resolveOutletForUser($org, $user, $outletId);
        $channel = self::menuChannelForOutlet($outlet);

        $query = Product::query()->with([
            'vat:id,vat_percentage,vat_code',
            'subCategory:id,category_id,subcategory_name',
            'subCategory.category:id,category_name,organization_id',
        ]);
        $this->catalogScope->scopeForUser($query, $user, $request);

        if (Schema::hasColumn('products', 'sell_on_bar') && Schema::hasColumn('products', 'sell_on_hotel')) {
            if ($channel === 'bar') {
                $query->where('products.sell_on_bar', true);
            } else {
                $query->where('products.sell_on_hotel', true);
            }
        }

        $menuGroup = strtolower(trim((string) $request->input('menu_group', '')));
        if ($channel === 'bar') {
            $this->excludeFoodCategories($query, $org);
            if ($menuGroup === 'food') {
                $query->whereRaw('1 = 0');
            }
        }
        if (in_array($menuGroup, ['food', 'drinks'], true) && ! ($channel === 'bar' && $menuGroup === 'food')) {
            $this->constrainCategoryMenuGroup($query, $org, $menuGroup);
        }

        if ($search !== '') {
            $exact = SqlLikeSearch::restrictToExactProductCodeIfPresent(
                $query,
                $search,
                'products.product_code',
            );
            if (! $exact) {
                SqlLikeSearch::applyProductSearch(
                    $query,
                    $search,
                    'products.product_code',
                    'products.product_name',
                );
            }
        }

        $soldQtyByCode = $this->soldQuantitiesByCode((int) $org->id, $days);

        $select = [
            'products.id',
            'products.product_code',
            'products.product_name',
            'products.unit_price',
            'products.vat_id',
            'products.sell_on_retail',
        ];
        if (Schema::hasColumn('products', 'sell_on_bar')) {
            $select[] = 'products.sell_on_bar';
            $select[] = 'products.sell_on_hotel';
        }
        if (Schema::hasColumn('products', 'image_path')) {
            $select[] = 'products.image_path';
        }

        $products = $query
            ->orderBy('products.product_name')
            ->limit(2000)
            ->get($select);

        $ranked = $products
            ->map(function (Product $product) use ($soldQtyByCode) {
                $code = (string) $product->product_code;
                $vatPct = (float) ($product->vat?->vat_percentage ?? 0);
                $sold = round((float) ($soldQtyByCode[$code] ?? 0), 4);
                $categoryName = strtolower((string) ($product->subCategory?->category?->category_name ?? ''));
                $menuGroup = $this->menuGroupForCategoryName($categoryName);

                return [
                    'id' => (int) $product->id,
                    'product_code' => $code,
                    'product_name' => (string) $product->product_name,
                    'unit_price' => round((float) $product->unit_price, 2),
                    'vat_id' => $product->vat_id ? (int) $product->vat_id : null,
                    'vat_percentage' => $vatPct,
                    'sold_qty' => $sold,
                    'is_popular' => $sold > 0,
                    'sell_on_bar' => (bool) ($product->sell_on_bar ?? true),
                    'sell_on_hotel' => (bool) ($product->sell_on_hotel ?? true),
                    'menu_group' => $menuGroup,
                    'category_name' => $product->subCategory?->category?->category_name
                        ? (string) $product->subCategory->category->category_name
                        : null,
                    'image_url' => $product->image_url,
                    'has_image' => ! empty($product->image_path),
                ];
            })
            ->sort(function (array $a, array $b) {
                $soldCmp = $b['sold_qty'] <=> $a['sold_qty'];
                if ($soldCmp !== 0) {
                    return $soldCmp;
                }

                return strcasecmp($a['product_name'], $b['product_name']);
            })
            ->values();

        $total = $ranked->count();
        $items = $ranked->slice($offset, $perPage)->values()->all();
        $nextOffset = $offset + count($items);
        $hasMore = $nextOffset < $total;

        return [
            'items' => $items,
            'grid_columns' => $settings['hotel_pos_grid_columns'],
            'catalog_limit' => $settings['hotel_pos_catalog_limit'],
            'collect_payment' => $settings['hotel_pos_collect_payment'],
            'stock_deduct_on_settle' => $settings['stock_deduct_on_settle'],
            'block_settle_if_insufficient' => $settings['block_settle_if_insufficient'],
            'outlet' => [
                'id' => $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
                'outlet_type' => $outlet->outlet_type,
                'menu_channel' => $channel,
                'menu_channel_label' => $channel === 'bar' ? 'Bar' : 'Restaurant',
            ],
            'menu_channel' => $channel,
            'popular_days' => $days,
            'searching' => $search !== '',
            'offset' => $offset,
            'per_page' => $perPage,
            'next_offset' => $hasMore ? $nextOffset : null,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    /** Classify Food vs Drinks chips from category name (same rules as menu_group filter). */
    protected function menuGroupForCategoryName(string $categoryName): ?string
    {
        if ($categoryName === '') {
            return null;
        }
        if (
            str_contains($categoryName, 'food')
            || str_contains($categoryName, 'kitchen')
            || str_contains($categoryName, 'meal')
        ) {
            return 'food';
        }
        if (
            str_contains($categoryName, 'drink')
            || str_contains($categoryName, 'beverage')
            || str_contains($categoryName, 'bar')
            || str_contains($categoryName, 'alcohol')
        ) {
            return 'drinks';
        }

        return null;
    }

    /** Bar POS never lists kitchen / food items, even when sell_on_bar is on. */
    protected function excludeFoodCategories($query, Organization $org): void
    {
        $query->where(function ($outer) use ($org) {
            $outer->whereDoesntHave('subCategory.category', function ($q) use ($org) {
                $q->where('categories.organization_id', $org->id)
                    ->where(function ($inner) {
                        $inner->whereRaw('LOWER(categories.category_name) LIKE ?', ['%food%'])
                            ->orWhereRaw('LOWER(categories.category_name) LIKE ?', ['%kitchen%'])
                            ->orWhereRaw('LOWER(categories.category_name) LIKE ?', ['%meal%']);
                    });
            });
        });
    }

    protected function constrainCategoryMenuGroup($query, Organization $org, string $menuGroup): void
    {
        $query->whereHas('subCategory.category', function ($q) use ($org, $menuGroup) {
            $q->where('categories.organization_id', $org->id);
            if ($menuGroup === 'food') {
                $q->where(function ($inner) {
                    $inner->whereRaw('LOWER(categories.category_name) LIKE ?', ['%food%'])
                        ->orWhereRaw('LOWER(categories.category_name) LIKE ?', ['%kitchen%'])
                        ->orWhereRaw('LOWER(categories.category_name) LIKE ?', ['%meal%']);
                });
            } else {
                $q->where(function ($inner) {
                    $inner->whereRaw('LOWER(categories.category_name) LIKE ?', ['%drink%'])
                        ->orWhereRaw('LOWER(categories.category_name) LIKE ?', ['%beverage%'])
                        ->orWhereRaw('LOWER(categories.category_name) LIKE ?', ['%bar%'])
                        ->orWhereRaw('LOWER(categories.category_name) LIKE ?', ['%alcohol%']);
                });
            }
        });
    }

    /**
     * @return array<string, float> product_code => qty
     */
    protected function soldQuantitiesByCode(int $organizationId, int $days): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();
        $totals = [];

        if (Schema::hasTable('sale_items') && Schema::hasTable('sales')) {
            $rows = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->where('s.organization_id', $organizationId)
                ->where('s.archived', 0)
                ->whereNotIn('s.status', ['cancelled', 'void', 'superseded'])
                ->where(function ($q) use ($since) {
                    $q->where('s.completed_at', '>=', $since)
                        ->orWhere(function ($inner) use ($since) {
                            $inner->whereNull('s.completed_at')
                                ->where('s.created_at', '>=', $since);
                        });
                })
                ->groupBy('si.product_code')
                ->selectRaw('si.product_code, SUM(si.quantity) as qty')
                ->get();

            foreach ($rows as $row) {
                $code = (string) $row->product_code;
                if ($code === '') {
                    continue;
                }
                $totals[$code] = ($totals[$code] ?? 0) + (float) $row->qty;
            }
        }

        if (Schema::hasTable('hospitality_check_lines') && Schema::hasTable('hospitality_checks')) {
            $rows = DB::table('hospitality_check_lines as hcl')
                ->join('hospitality_checks as hc', 'hc.id', '=', 'hcl.check_id')
                ->where('hc.organization_id', $organizationId)
                ->whereIn('hc.status', ['paid', 'settled', 'posted_to_folio'])
                ->where(function ($q) use ($since) {
                    $q->where('hc.closed_at', '>=', $since)
                        ->orWhere(function ($inner) use ($since) {
                            $inner->whereNull('hc.closed_at')
                                ->where('hc.updated_at', '>=', $since);
                        });
                })
                ->whereNotNull('hcl.product_code')
                ->groupBy('hcl.product_code')
                ->selectRaw('hcl.product_code, SUM(hcl.qty) as qty')
                ->get();

            foreach ($rows as $row) {
                $code = (string) $row->product_code;
                if ($code === '') {
                    continue;
                }
                $totals[$code] = ($totals[$code] ?? 0) + (float) $row->qty;
            }
        }

        return $totals;
    }
}
