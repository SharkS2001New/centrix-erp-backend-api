<?php

namespace App\Services\Sales;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockReservation;
use App\Models\SystemSetting;
use App\Services\Inventory\SaleStockLocationResolver;
use Illuminate\Support\Collection;

/**
 * Tax invoice / thermal receipt print requires fulfillable stock unless the org
 * allows negative stock. Soft holds (reservations) count for this sale only when
 * physical on-hand still covers the reserved quantity.
 */
class SaleInvoicePrintStockGate
{
    /** @var array<int, bool> */
    protected array $allowBelowCache = [];

    /** @var array<int, array{inventory: array<string, mixed>, sales: array<string, mixed>}> */
    protected array $moduleSettingsCache = [];

    public function allows(Sale $sale): bool
    {
        $orgId = (int) ($sale->organization_id ?? 0);
        if ($this->organizationAllowsBelowStock($orgId > 0 ? $orgId : null)) {
            return true;
        }

        if ((int) ($sale->stock_balanced ?? 0) === 1) {
            return true;
        }

        $map = $this->allowsMany(collect([$sale]));

        return (bool) ($map[(int) $sale->id] ?? false);
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @return array<int, bool> sale_id => allowed
     */
    public function allowsMany(Collection $sales): array
    {
        $result = [];
        if ($sales->isEmpty()) {
            return $result;
        }

        $needsCheck = [];
        foreach ($sales as $sale) {
            $saleId = (int) $sale->id;
            $orgId = (int) ($sale->organization_id ?? 0);
            if ($this->organizationAllowsBelowStock($orgId > 0 ? $orgId : null)) {
                $result[$saleId] = true;

                continue;
            }
            if ((int) ($sale->stock_balanced ?? 0) === 1) {
                $result[$saleId] = true;

                continue;
            }
            $needsCheck[$saleId] = $sale;
        }

        if ($needsCheck === []) {
            return $result;
        }

        $saleIds = array_keys($needsCheck);
        $items = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->get(['sale_id', 'product_code', 'quantity', 'on_wholesale_retail']);

        $itemsBySale = $items->groupBy(fn (SaleItem $item) => (int) $item->sale_id);

        $branchIds = [];
        $productCodes = [];
        foreach ($needsCheck as $sale) {
            $branchIds[(int) $sale->branch_id] = true;
            foreach ($itemsBySale[(int) $sale->id] ?? [] as $item) {
                $code = (string) $item->product_code;
                if ($code !== '') {
                    $productCodes[$code] = true;
                }
            }
        }

        $onHand = $this->loadOnHandMap(array_keys($branchIds), array_keys($productCodes));
        $reservedAll = $this->loadActiveReservedMap(array_keys($branchIds), array_keys($productCodes));
        $reservedBySale = $this->loadSaleReservedMap($saleIds);

        foreach ($needsCheck as $saleId => $sale) {
            $branchId = (int) $sale->branch_id;
            $saleItems = $itemsBySale[$saleId] ?? collect();
            if ($saleItems->isEmpty()) {
                $result[$saleId] = false;

                continue;
            }

            $ok = true;
            /** @var array<string, float> $neededByKey */
            $neededByKey = [];
            foreach ($saleItems as $item) {
                $code = (string) $item->product_code;
                if ($code === '') {
                    $ok = false;
                    break;
                }
                $location = $this->resolveItemLocationHint($sale, $item, $reservedBySale[$saleId] ?? []);
                $key = $this->stockKey($branchId, $code, $location);
                $neededByKey[$key] = ($neededByKey[$key] ?? 0) + max(0, (float) $item->quantity);
            }

            if ($ok) {
                foreach ($neededByKey as $key => $needed) {
                    if ($needed <= 0.0001) {
                        continue;
                    }
                    $physical = (float) ($onHand[$key] ?? 0);
                    $totalReserved = (float) ($reservedAll[$key] ?? 0);
                    $thisSaleReserved = (float) (($reservedBySale[$saleId][$key] ?? 0));
                    // Stock available to this sale = on-hand minus other carts'/sales' holds.
                    $availableToSale = $physical - max(0, $totalReserved - $thisSaleReserved);
                    if ($availableToSale + 0.0001 < $needed) {
                        $ok = false;
                        break;
                    }
                }
            }

            $result[$saleId] = $ok;
        }

        return $result;
    }

    protected function organizationAllowsBelowStock(?int $organizationId): bool
    {
        if (! $organizationId) {
            return false;
        }
        if (array_key_exists($organizationId, $this->allowBelowCache)) {
            return $this->allowBelowCache[$organizationId];
        }

        $system = SystemSetting::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->first();

        return $this->allowBelowCache[$organizationId] = (bool) ($system?->allow_below_stock ?? false);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  list<string>  $productCodes
     * @return array<string, float>
     */
    protected function loadOnHandMap(array $branchIds, array $productCodes): array
    {
        $map = [];
        if ($branchIds === [] || $productCodes === []) {
            return $map;
        }

        $rows = CurrentStock::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('product_code', $productCodes)
            ->get(['branch_id', 'product_code', 'shop_quantity', 'store_quantity']);

        foreach ($rows as $row) {
            $branchId = (int) $row->branch_id;
            $code = (string) $row->product_code;
            $map[$this->stockKey($branchId, $code, 'shop')] = (float) $row->shop_quantity;
            $map[$this->stockKey($branchId, $code, 'store')] = (float) $row->store_quantity;
        }

        return $map;
    }

    /**
     * @param  list<int>  $branchIds
     * @param  list<string>  $productCodes
     * @return array<string, float>
     */
    protected function loadActiveReservedMap(array $branchIds, array $productCodes): array
    {
        $map = [];
        if ($branchIds === [] || $productCodes === []) {
            return $map;
        }

        $rows = StockReservation::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('product_code', $productCodes)
            ->whereNull('released_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->selectRaw('branch_id, product_code, stock_location, SUM(quantity) as qty')
            ->groupBy('branch_id', 'product_code', 'stock_location')
            ->get();

        foreach ($rows as $row) {
            $key = $this->stockKey(
                (int) $row->branch_id,
                (string) $row->product_code,
                (string) ($row->stock_location ?: 'shop'),
            );
            $map[$key] = (float) $row->qty;
        }

        return $map;
    }

    /**
     * @param  list<int>  $saleIds
     * @return array<int, array<string, float>> sale_id => [stockKey => qty]
     */
    protected function loadSaleReservedMap(array $saleIds): array
    {
        $map = [];
        if ($saleIds === []) {
            return $map;
        }

        $rows = StockReservation::query()
            ->whereIn('sale_id', $saleIds)
            ->whereNull('released_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->selectRaw('sale_id, branch_id, product_code, stock_location, SUM(quantity) as qty')
            ->groupBy('sale_id', 'branch_id', 'product_code', 'stock_location')
            ->get();

        foreach ($rows as $row) {
            $saleId = (int) $row->sale_id;
            $key = $this->stockKey(
                (int) $row->branch_id,
                (string) $row->product_code,
                (string) ($row->stock_location ?: 'shop'),
            );
            $map[$saleId][$key] = (float) $row->qty;
        }

        return $map;
    }

    /**
     * Prefer the location this sale already reserved; otherwise infer shop vs store
     * from line pricing mode and org stock settings (same as checkout reservations).
     *
     * @param  array<string, float>  $saleReserved
     */
    protected function resolveItemLocationHint(Sale $sale, SaleItem $item, array $saleReserved): string
    {
        $branchId = (int) $sale->branch_id;
        $code = (string) $item->product_code;
        $shopKey = $this->stockKey($branchId, $code, 'shop');
        $storeKey = $this->stockKey($branchId, $code, 'store');
        if (($saleReserved[$storeKey] ?? 0) > ($saleReserved[$shopKey] ?? 0)) {
            return 'store';
        }
        if (($saleReserved[$shopKey] ?? 0) > 0) {
            return 'shop';
        }

        $orgId = (int) ($sale->organization_id ?? 0);
        if ($orgId <= 0) {
            return 'shop';
        }

        $settings = $this->moduleSettingsForOrganization($orgId);
        $product = Product::query()
            ->where('organization_id', $orgId)
            ->where('product_code', $code)
            ->first();

        if (! $product) {
            return SaleStockLocationResolver::defaultChannelLocation(
                (string) ($sale->channel ?: 'backend'),
                $settings['inventory'],
            );
        }

        return SaleStockLocationResolver::forLine(
            (string) ($sale->channel ?: 'backend'),
            $settings['inventory'],
            $settings['sales'],
            $product,
            (bool) $item->on_wholesale_retail,
        );
    }

    /** @return array{inventory: array<string, mixed>, sales: array<string, mixed>} */
    protected function moduleSettingsForOrganization(int $organizationId): array
    {
        if (isset($this->moduleSettingsCache[$organizationId])) {
            return $this->moduleSettingsCache[$organizationId];
        }

        $system = SystemSetting::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->first();
        $merged = is_array($system?->module_settings) ? $system->module_settings : [];

        return $this->moduleSettingsCache[$organizationId] = [
            'inventory' => is_array($merged['inventory'] ?? null) ? $merged['inventory'] : [],
            'sales' => is_array($merged['sales'] ?? null) ? $merged['sales'] : [],
        ];
    }

    protected function stockKey(int $branchId, string $productCode, string $location): string
    {
        return $branchId.'|'.$productCode.'|'.$location;
    }
}
