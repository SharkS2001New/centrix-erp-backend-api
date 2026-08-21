<?php

namespace App\Services\Background;

use App\Models\Uom;
use App\Services\Inventory\StockUomDisplayService;

/**
 * Format inventory quantities for CSV/PDF exports the same way the web UI
 * shows them (mixed packaging labels), instead of raw base units.
 */
class ExportInventoryQtyFormatter
{
    /** Keys that look like qty fields but are not base-unit inventory stock. */
    private const EXCLUDED_KEYS = [
        'conversion_factor',
        'unit_cost',
        'unit_price',
        'unit_id',
        'cost_price',
        'effective_unit_cost',
        'last_cost_price',
        'wholesale_price',
        'middle_price',
        'incident_count',
        'transfer_count',
        'reservation_count',
    ];

    private const LPO_PACK_QTY_KEYS = [
        'ordered_qty',
        'received_qty',
        'pending_qty',
        'total_qty_ordered',
        'total_qty_received',
        'total_qty_pending',
    ];

    public function __construct(
        protected StockUomDisplayService $stockUom,
    ) {}

    public function isInventoryQtyKey(string $key): bool
    {
        if ($key === '' || in_array($key, self::EXCLUDED_KEYS, true)) {
            return false;
        }
        if ($this->isLpoPackQtyKey($key)) {
            return false;
        }

        return (bool) preg_match(
            '/qty|quantity|units_received|units_moved|quantity_change|quantity_before|quantity_after|quantity_moved|total_moved|reserved_qty|reorder_point|on_hand|current_shop|current_store|shop_quantity|store_quantity|total_base|total_qty|in_qty|out_qty/i',
            $key,
        );
    }

    public function isLpoPackQtyKey(string $key): bool
    {
        return in_array($key, self::LPO_PACK_QTY_KEYS, true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function format(mixed $value, array $row, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ! is_numeric(trim($value))) {
            // Already formatted (e.g. "2 Bag, 5 kg") — leave alone.
            return $value;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $baseQty = (float) $value;
        $uom = $this->resolveUom($row);

        if ($this->isLpoPackQtyKey($key)) {
            return $this->formatLpoPackQty($baseQty, $uom);
        }

        if (! $this->isInventoryQtyKey($key)) {
            return null;
        }

        if ($uom === null) {
            return $this->formatPlainNumber($baseQty);
        }

        return $this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function resolveUom(array $row): ?Uom
    {
        $embedded = $row['uom'] ?? $row['product_uom'] ?? null;
        if ($embedded instanceof Uom) {
            return $embedded;
        }
        if (is_array($embedded)) {
            return $this->uomFromAttributes($embedded);
        }

        $attrs = [
            'full_name' => $row['uom_name'] ?? $row['uom_label'] ?? $row['package_name'] ?? null,
            'conversion_factor' => $row['conversion_factor'] ?? $row['uom_factor'] ?? $row['factor'] ?? 1,
            'small_packaging_label' => $row['small_packaging_label'] ?? null,
            'middle_packaging_label' => $row['middle_packaging_label'] ?? null,
            'middle_factor' => $row['middle_factor'] ?? null,
            'uom_type' => $row['uom_type'] ?? null,
            'uses_small_packaging' => $row['uses_small_packaging'] ?? true,
        ];

        if (($attrs['full_name'] ?? null) === null
            && ($attrs['uom_type'] ?? null) === null
            && ($attrs['small_packaging_label'] ?? null) === null
            && ! array_key_exists('conversion_factor', $row)
            && ! array_key_exists('uom_factor', $row)
        ) {
            return null;
        }

        return $this->uomFromAttributes($attrs);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function uomFromAttributes(array $attrs): Uom
    {
        $uom = new Uom;
        $uom->forceFill([
            'full_name' => $attrs['full_name'] ?? $attrs['uom_name'] ?? null,
            'conversion_factor' => (float) ($attrs['conversion_factor'] ?? 1) ?: 1,
            'small_packaging_label' => $attrs['small_packaging_label'] ?? null,
            'middle_packaging_label' => $attrs['middle_packaging_label'] ?? null,
            'middle_factor' => $attrs['middle_factor'] ?? null,
            'uom_type' => $attrs['uom_type'] ?? null,
            'uses_small_packaging' => array_key_exists('uses_small_packaging', $attrs)
                ? (bool) $attrs['uses_small_packaging']
                : true,
            'measure_name' => $attrs['measure_name'] ?? null,
        ]);

        return $uom;
    }

    protected function formatLpoPackQty(float $packQty, ?Uom $uom): string
    {
        $label = trim((string) ($uom?->full_name ?? ''));
        if ($label === '') {
            $label = 'packs';
        }

        return $this->formatPlainNumber($packQty).' '.$label;
    }

    protected function formatPlainNumber(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.0001) {
            return number_format((int) round($qty), 0, '.', ',');
        }

        $formatted = rtrim(rtrim(number_format($qty, 3, '.', ','), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
