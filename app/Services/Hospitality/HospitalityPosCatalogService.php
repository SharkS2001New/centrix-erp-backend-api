<?php

namespace App\Services\Hospitality;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Support\SqlLikeSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HospitalityPosCatalogService
{
    public function __construct(
        protected ProductCatalogScopeService $catalogScope,
    ) {}

    /**
     * Active products for hotel POS: most-sold first, then name.
     *
     * @return array{items: list<array<string, mixed>>, grid_columns: int, popular_days: int}
     */
    public function catalog(Organization $org, User $user, Request $request): array
    {
        $days = max(7, min(180, (int) $request->input('popular_days', 90)));
        $perPage = min(200, max(1, (int) $request->input('per_page', 120)));
        $search = trim((string) $request->input('q', ''));

        $query = Product::query()->with('vat:id,vat_percentage,vat_code');
        $this->catalogScope->scopeForUser($query, $user, $request);

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

        $products = $query
            ->orderBy('products.product_name')
            ->limit($perPage)
            ->get([
                'products.id',
                'products.product_code',
                'products.product_name',
                'products.unit_price',
                'products.vat_id',
                'products.sell_on_retail',
            ]);

        $items = $products
            ->map(function (Product $product) use ($soldQtyByCode) {
                $code = (string) $product->product_code;
                $vatPct = (float) ($product->vat?->vat_percentage ?? 0);

                return [
                    'id' => (int) $product->id,
                    'product_code' => $code,
                    'product_name' => (string) $product->product_name,
                    'unit_price' => round((float) $product->unit_price, 2),
                    'vat_id' => $product->vat_id ? (int) $product->vat_id : null,
                    'vat_percentage' => $vatPct,
                    'sold_qty' => round((float) ($soldQtyByCode[$code] ?? 0), 4),
                    'is_popular' => ((float) ($soldQtyByCode[$code] ?? 0)) > 0,
                ];
            })
            ->sort(function (array $a, array $b) {
                $soldCmp = $b['sold_qty'] <=> $a['sold_qty'];
                if ($soldCmp !== 0) {
                    return $soldCmp;
                }

                return strcasecmp($a['product_name'], $b['product_name']);
            })
            ->values()
            ->all();

        return [
            'items' => $items,
            'grid_columns' => HospitalityPosSettings::gridColumnsForOrganization($org),
            'popular_days' => $days,
        ];
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
                ->whereIn('hc.status', ['settled', 'posted_to_folio'])
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
