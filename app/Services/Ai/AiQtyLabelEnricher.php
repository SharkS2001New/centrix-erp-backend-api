<?php

namespace App\Services\Ai;

use App\Models\Uom;
use App\Services\Inventory\StockUomDisplayService;
use App\Services\Inventory\StockCostCalculation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attach Centrix stock UoM labels (e.g. "2 Bag, 40 kg") to AI tool qty fields.
 */
class AiQtyLabelEnricher
{
    public function __construct(
        protected StockUomDisplayService $stockUom,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function enrichProductRows(
        int $organizationId,
        array $rows,
        string $qtyKey = 'qty',
        string $labelKey = 'qty_label',
    ): array {
        if ($rows === [] || $organizationId <= 0 || ! Schema::hasTable('products')) {
            return $rows;
        }

        $codes = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['product_code'] ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        $codeList = array_keys($codes);
        if ($codeList === []) {
            return $rows;
        }

        $uomByCode = $this->uomsByProductCode($organizationId, $codeList);

        return array_map(function (array $row) use ($qtyKey, $labelKey, $uomByCode) {
            $code = trim((string) ($row['product_code'] ?? ''));
            $qty = (float) ($row[$qtyKey] ?? 0);
            $uom = $code !== '' ? ($uomByCode[$code] ?? null) : null;
            $label = $this->stockUom->formatMixedStockDisplay($qty, $uom)['text'];
            $row[$labelKey] = $label;
            // Prefer labeled qty in chat; keep numeric for sorting / math.
            if ($labelKey === 'qty_label' && ! isset($row['qty_base'])) {
                $row['qty_base'] = round($qty, 4);
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  list<string>  $productCodes
     * @return array<string, Uom|null>
     */
    protected function uomsByProductCode(int $organizationId, array $productCodes): array
    {
        $query = DB::table('products as p')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.unit_id')
            ->where('p.organization_id', $organizationId)
            ->whereIn('p.product_code', $productCodes)
            ->whereNull('p.deleted_at');

        if (Schema::hasColumn('uoms', 'organization_id')) {
            $query->where(function ($q) use ($organizationId) {
                $q->whereNull('u.organization_id')
                    ->orWhere('u.organization_id', $organizationId);
            });
        }

        $map = [];
        foreach ($query->get([
            'p.product_code',
            'u.full_name',
            'u.conversion_factor',
            'u.small_packaging_label',
            'u.middle_packaging_label',
            'u.middle_factor',
            'u.uom_type',
            'u.uses_small_packaging',
        ]) as $row) {
            $code = (string) $row->product_code;
            $factor = StockCostCalculation::normalizedConversionFactor($row->conversion_factor ?? 1);
            $map[$code] = new Uom([
                'full_name' => $row->full_name,
                'conversion_factor' => $factor,
                'small_packaging_label' => $row->small_packaging_label,
                'middle_packaging_label' => $row->middle_packaging_label,
                'middle_factor' => $row->middle_factor,
                'uom_type' => $row->uom_type,
                'uses_small_packaging' => (bool) ($row->uses_small_packaging ?? true),
            ]);
        }

        return $map;
    }
}
