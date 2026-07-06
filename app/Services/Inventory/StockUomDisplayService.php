<?php

namespace App\Services\Inventory;

use App\Models\Uom;

/**
 * Mixed packaging display for stock quantities — mirrors web src/lib/stock-uom.js.
 * Base quantities are stored in the smallest unit; display splits into full / middle / small.
 */
class StockUomDisplayService
{
    /** @return array{text: string, parts: array<int, array{label: string, qty: float}>} */
    public function formatMixedStockDisplay(float $baseQty, ?Uom $uom): array
    {
        $parts = $this->splitBaseToHierarchy($baseQty, $uom);
        $text = collect($parts)
            ->map(fn (array $part) => $this->formatDisplayQty($part['qty']).' '.$part['label'])
            ->implode(', ');

        if ($text === '') {
            $text = '0 '.$this->smallPackagingLabel($uom);
        }

        return ['text' => $text, 'parts' => $parts];
    }

    /** @return array{quantity_label: string, pack_breakdown: string} */
    public function fulfillmentQuantityLabels(float $baseQty, ?Uom $uom): array
    {
        $display = $this->formatMixedStockDisplay($baseQty, $uom);
        $smallLabel = $this->smallPackagingLabel($uom);
        $baseText = $this->formatDisplayQty($baseQty).' '.$smallLabel;

        return [
            'quantity_label' => $baseText,
            'pack_breakdown' => $display['text'],
        ];
    }

    /** @return array<int, array{label: string, qty: float}> */
    public function splitBaseToHierarchy(float $baseQty, ?Uom $uom): array
    {
        $factor = $this->conversionFactor($uom);
        $remaining = $baseQty;

        if ($this->isFullPackageOnly($uom)) {
            $fullLabel = $this->fullPackageLabel($uom);
            $qty = $factor > 1 ? $remaining / $factor : $remaining;

            return [['label' => $fullLabel, 'qty' => $qty]];
        }

        $smallLabel = $this->smallPackagingLabel($uom);
        $parts = [];

        if ($factor <= 1) {
            if ($remaining > 0.0001 || abs($remaining) < 0.0001) {
                $parts[] = ['label' => $smallLabel, 'qty' => $remaining];
            }

            return $parts !== [] ? $parts : [['label' => $smallLabel, 'qty' => 0.0]];
        }

        $fullLabel = $this->fullPackageLabel($uom);
        $fullCount = (int) floor($remaining / $factor);
        $remaining = round($remaining - ($fullCount * $factor), 4);
        if ($fullCount > 0) {
            $parts[] = ['label' => $fullLabel, 'qty' => (float) $fullCount];
        }

        $middleLabel = trim((string) ($uom?->middle_packaging_label ?? ''));
        $middleFactor = (float) ($uom?->middle_factor ?? 0);
        if ($middleLabel !== '' && $middleFactor > 1) {
            $midCount = (int) floor($remaining / $middleFactor);
            $remaining = round($remaining - ($midCount * $middleFactor), 4);
            if ($midCount > 0) {
                $parts[] = ['label' => $middleLabel, 'qty' => (float) $midCount];
            }
        }

        if ($remaining > 0.0001) {
            $parts[] = ['label' => $smallLabel, 'qty' => $remaining];
        }

        if ($parts === []) {
            $parts[] = ['label' => $fullLabel, 'qty' => 0.0];
        }

        return $parts;
    }

    protected function conversionFactor(?Uom $uom): float
    {
        $factor = (float) ($uom?->conversion_factor ?? 1);

        return $factor > 0 ? $factor : 1.0;
    }

    protected function isFullPackageOnly(?Uom $uom): bool
    {
        return ($uom?->uses_small_packaging ?? true) === false;
    }

    protected function smallPackagingLabel(?Uom $uom): string
    {
        $explicit = trim((string) ($uom?->small_packaging_label ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $type = trim((string) ($uom?->uom_type ?? ''));

        return $type !== '' ? $type : 'pcs';
    }

    protected function fullPackageLabel(?Uom $uom, string $fallback = 'pack'): string
    {
        $name = trim((string) ($uom?->full_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $middle = trim((string) ($uom?->middle_packaging_label ?? ''));

        return $middle !== '' ? $middle : $fallback;
    }

    protected function formatDisplayQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.0001) {
            return number_format((int) round($qty), 0, '.', ',');
        }

        $formatted = rtrim(rtrim(number_format($qty, 3, '.', ','), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
