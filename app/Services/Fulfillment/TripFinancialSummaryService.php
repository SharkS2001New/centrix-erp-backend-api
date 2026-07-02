<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Sale;
use App\Services\Accounting\SaleCogsCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TripFinancialSummaryService
{
    public function __construct(protected SaleCogsCalculator $cogsCalculator) {}

    /** @return array{order_count: int, total_amount: float, total_profit: float, profit_margin_percent: float|null} */
    public function emptySummary(): array
    {
        return [
            'order_count' => 0,
            'total_amount' => 0.0,
            'total_profit' => 0.0,
            'profit_margin_percent' => null,
        ];
    }

    /** @return array{order_count: int, total_amount: float, total_profit: float, profit_margin_percent: float|null} */
    public function summarizeForTrip(DispatchTrip $trip): array
    {
        if (! $trip->relationLoaded('sales')) {
            $trip->load('sales');
        }

        return $this->summarizeSales($trip->sales);
    }

    /**
     * @param  list<int>  $tripIds
     * @return array<int, array{order_count: int, total_amount: float, total_profit: float, profit_margin_percent: float|null}>
     */
    public function summarizeForTripIds(array $tripIds): array
    {
        $tripIds = array_values(array_unique(array_map('intval', $tripIds)));
        if ($tripIds === []) {
            return [];
        }

        $rows = DB::table('dispatch_trip_sales')
            ->whereIn('trip_id', $tripIds)
            ->select(['trip_id', 'sale_id'])
            ->get();

        if ($rows->isEmpty()) {
            return array_fill_keys($tripIds, $this->emptySummary());
        }

        $saleIds = $rows->pluck('sale_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $sales = Sale::query()
            ->with(['items.product'])
            ->whereIn('id', $saleIds)
            ->get()
            ->keyBy('id');

        $salesByTrip = [];
        foreach ($rows as $row) {
            $tripId = (int) $row->trip_id;
            $sale = $sales->get((int) $row->sale_id);
            if (! $sale) {
                continue;
            }
            $salesByTrip[$tripId] ??= collect();
            $salesByTrip[$tripId]->push($sale);
        }

        $summaries = [];
        foreach ($tripIds as $tripId) {
            $summaries[$tripId] = $this->summarizeSales($salesByTrip[$tripId] ?? collect());
        }

        return $summaries;
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @return array{order_count: int, total_amount: float, total_profit: float, profit_margin_percent: float|null}
     */
    protected function summarizeSales(Collection $sales): array
    {
        if ($sales->isEmpty()) {
            return $this->emptySummary();
        }

        $totalAmount = 0.0;
        $netRevenue = 0.0;
        $totalCost = 0.0;

        foreach ($sales as $sale) {
            $orderTotal = (float) $sale->order_total;
            $vat = (float) ($sale->total_vat ?? 0);
            $totalAmount += $orderTotal;
            $netRevenue += $orderTotal - $vat;
            $totalCost += $this->cogsCalculator->totalCostForSale($sale);
        }

        $totalAmount = round($totalAmount, 2);
        $totalProfit = round($netRevenue - $totalCost, 2);
        $profitMarginPercent = $netRevenue > 0
            ? round(($totalProfit / $netRevenue) * 100, 1)
            : null;

        return [
            'order_count' => $sales->count(),
            'total_amount' => $totalAmount,
            'total_profit' => $totalProfit,
            'profit_margin_percent' => $profitMarginPercent,
        ];
    }
}
