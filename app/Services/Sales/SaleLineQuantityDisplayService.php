<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\Uom;

/**
 * Display quantities for cart and order lines — mirrors web pos-line.js / mobile pos_line.dart.
 * Stored quantity is always in base (smallest) UOM; display uses entry units for wholesale packs.
 */
class SaleLineQuantityDisplayService
{
    public function entryQtyFromBase(float $baseQty, Product $product, bool $isRetailLine): float
    {
        $unit = $this->productUnit($product);
        $factor = max(1.0, (float) ($unit?->conversion_factor ?? 1));
        $rps = $product->exists
            ? RetailPackageSetting::query()->where('product_code', $product->product_code)->first()
            : null;

        if ($isRetailLine && $this->productHasRetailTiers($rps)) {
            return $baseQty;
        }

        if ($factor > 1 && ! $isRetailLine) {
            return round($baseQty / $factor, 4);
        }

        return $baseQty;
    }

    public function formatLineQtyDisplay(
        float $baseQty,
        Product $product,
        bool $isRetailLine,
        ?string $uomLabel = null,
    ): string {
        $entryQty = $this->entryQtyFromBase($baseQty, $product, $isRetailLine);
        $label = trim((string) $uomLabel);
        if ($label === '') {
            $label = $this->defaultLineUomLabel($product, $isRetailLine);
        }

        return trim($this->formatDisplayQty($entryQty).' '.$label);
    }

    protected function defaultLineUomLabel(Product $product, bool $isRetailLine): string
    {
        $uom = $this->productUnit($product);
        if (! $uom) {
            return 'units';
        }

        if ($isRetailLine) {
            return $this->smallPackagingLabel($uom);
        }

        if ((float) $uom->conversion_factor > 1) {
            return $this->fullPackageLabel($uom);
        }

        return $this->smallPackagingLabel($uom);
    }

    protected function productHasRetailTiers(?RetailPackageSetting $rps): bool
    {
        if (! $rps) {
            return false;
        }

        $raw = $rps->pricing_tiers;
        if (is_array($raw) && $raw !== []) {
            return true;
        }

        return (float) ($rps->max_qty_measure ?? 0) > 0;
    }

    protected function smallPackagingLabel(Uom $uom): string
    {
        $explicit = trim((string) ($uom->small_packaging_label ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return trim((string) ($uom->measure_name ?? 'units')) ?: 'units';
    }

    protected function fullPackageLabel(Uom $uom): string
    {
        $name = trim((string) ($uom->full_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $middle = trim((string) ($uom->middle_packaging_label ?? ''));

        return $middle !== '' ? $middle : 'pack';
    }

    protected function formatDisplayQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.0001) {
            return number_format((int) round($qty), 0, '.', ',');
        }

        $formatted = rtrim(rtrim(number_format($qty, 3, '.', ','), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    protected function productUnit(Product $product): ?Uom
    {
        if ($product->relationLoaded('unit')) {
            return $product->unit;
        }

        if ($product->exists) {
            $product->loadMissing('unit');
        }

        return $product->unit;
    }
}
