<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Expense;
use App\Models\CustomerReturn;
use App\Models\Sale;
use App\Services\Accounting\SaleCogsCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TripFinancialSummaryService
{
    public function __construct(protected SaleCogsCalculator $cogsCalculator) {}

    /** @return array<string, mixed> */
    public function emptySummary(): array
    {
        return [
            'order_count' => 0,
            'total_amount' => 0.0,
            'planned_amount' => 0.0,
            'delivered_amount' => 0.0,
            'returned_amount' => 0.0,
            'failed_amount' => 0.0,
            'actual_amount' => 0.0,
            'delivered_order_count' => 0,
            'partial_order_count' => 0,
            'failed_order_count' => 0,
            'unresolved_order_count' => 0,
            'net_revenue' => 0.0,
            'total_profit' => 0.0,
            'profit_margin_percent' => null,
            'expenses' => [],
            'total_expenses' => 0.0,
            'net_profit' => 0.0,
            'net_profit_margin_percent' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function summarizeForTrip(DispatchTrip $trip): array
    {
        if (! $trip->relationLoaded('sales')) {
            $trip->load('sales');
        }

        $summary = $this->summarizeSales($trip->sales);

        return $this->mergeExpensesIntoSummary($summary, $this->expensesForTrip((int) $trip->id));
    }

    /**
     * @param  list<int>  $tripIds
     * @return array<int, array<string, mixed>>
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

        $expensesByTrip = $this->expensesForTripIds($tripIds)->groupBy('dispatch_trip_id');

        $summaries = [];
        foreach ($tripIds as $tripId) {
            $summary = $this->summarizeSales($salesByTrip[$tripId] ?? collect());
            $summaries[$tripId] = $this->mergeExpensesIntoSummary(
                $summary,
                $expensesByTrip->get($tripId, collect()),
            );
        }

        return $summaries;
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @return array<string, mixed>
     */
    protected function summarizeSales(Collection $sales): array
    {
        if ($sales->isEmpty()) {
            return $this->emptySummary();
        }

        $totalAmount = 0.0;
        $deliveredAmount = 0.0;
        $returnedAmount = 0.0;
        $failedAmount = 0.0;
        $actualAmount = 0.0;
        $deliveredOrderCount = 0;
        $partialOrderCount = 0;
        $failedOrderCount = 0;
        $unresolvedOrderCount = 0;
        $netRevenue = 0.0;
        $totalCost = 0.0;
        $returnAmounts = $this->returnAmountsBySale(
            $sales->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        foreach ($sales as $sale) {
            $orderTotal = (float) $sale->order_total;
            $vat = (float) ($sale->total_vat ?? 0);
            $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
            $outcome = (string) ($meta['driver_delivery_outcome'] ?? '');
            $isDelivered = in_array((string) $sale->status, ['delivered', 'completed'], true);
            $isPartial = $outcome === 'partial';
            $isFailed = ($outcome === 'failed' || (string) $sale->status === 'cancelled') && ! $isDelivered;
            $returnAmount = min($orderTotal, (float) ($returnAmounts[(int) $sale->id] ?? 0));

            $totalAmount += $orderTotal;
            $returnedAmount += $returnAmount;
            if ($isFailed) {
                $failedOrderCount++;
                $failedAmount += $orderTotal;
            } elseif ($isPartial && $isDelivered) {
                $partialOrderCount++;
                $actualAmount += max(0, $orderTotal - $returnAmount);
            } elseif ($isDelivered) {
                $deliveredOrderCount++;
                $deliveredAmount += $orderTotal;
                $actualAmount += max(0, $orderTotal - $returnAmount);
            } else {
                $unresolvedOrderCount++;
            }
            $netRevenue += $orderTotal - $vat;
            $totalCost += $this->cogsCalculator->totalCostForSale($sale);
        }

        $totalAmount = round($totalAmount, 2);
        $deliveredAmount = round($deliveredAmount, 2);
        $returnedAmount = round($returnedAmount, 2);
        $failedAmount = round($failedAmount, 2);
        $actualAmount = round($actualAmount, 2);
        $netRevenue = round($netRevenue, 2);
        $totalProfit = round($netRevenue - $totalCost, 2);
        $profitMarginPercent = $netRevenue > 0
            ? round(($totalProfit / $netRevenue) * 100, 1)
            : null;

        return [
            'order_count' => $sales->count(),
            'total_amount' => $totalAmount,
            'planned_amount' => $totalAmount,
            'delivered_amount' => $deliveredAmount,
            'returned_amount' => $returnedAmount,
            'failed_amount' => $failedAmount,
            'actual_amount' => $actualAmount,
            'delivered_order_count' => $deliveredOrderCount,
            'partial_order_count' => $partialOrderCount,
            'failed_order_count' => $failedOrderCount,
            'unresolved_order_count' => $unresolvedOrderCount,
            'net_revenue' => $netRevenue,
            'total_profit' => $totalProfit,
            'profit_margin_percent' => $profitMarginPercent,
            'expenses' => [],
            'total_expenses' => 0.0,
            'net_profit' => $totalProfit,
            'net_profit_margin_percent' => $profitMarginPercent,
        ];
    }

    /**
     * @param  list<int>  $saleIds
     * @return array<int, float>
     */
    protected function returnAmountsBySale(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if ($saleIds === []) {
            return [];
        }

        return CustomerReturn::query()
            ->whereIn('sale_id', $saleIds)
            ->whereIn('status', ['pending', 'approved'])
            ->select('sale_id', DB::raw('SUM(total_amount) as total_returned'))
            ->groupBy('sale_id')
            ->pluck('total_returned', 'sale_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /** @param  list<int>  $tripIds @return Collection<int, Expense> */
    protected function expensesForTripIds(array $tripIds): Collection
    {
        if ($tripIds === [] || ! Schema::hasColumn('expenses', 'dispatch_trip_id')) {
            return collect();
        }

        return Expense::query()
            ->with('expenseGroup')
            ->whereIn('dispatch_trip_id', $tripIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Expense> */
    protected function expensesForTrip(int $tripId): Collection
    {
        return $this->expensesForTripIds([$tripId]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  Collection<int, Expense>  $expenses
     * @return array<string, mixed>
     */
    protected function mergeExpensesIntoSummary(array $summary, Collection $expenses): array
    {
        $expenseRows = [];
        $totalExpenses = 0.0;

        foreach ($expenses as $expense) {
            $amount = round((float) $expense->expense_amount, 2);
            $totalExpenses += $amount;
            $label = trim((string) ($expense->expenseGroup?->group_name ?? ''));
            if ($label === '') {
                $label = trim((string) ($expense->description ?? ''));
            }
            if ($label === '') {
                $label = 'Expense';
            }

            $expenseRows[] = [
                'id' => (int) $expense->id,
                'label' => $label,
                'amount' => $amount,
            ];
        }

        $totalExpenses = round($totalExpenses, 2);
        $netProfit = round((float) $summary['total_profit'] - $totalExpenses, 2);
        $netRevenue = (float) ($summary['net_revenue'] ?? 0);
        $netProfitMarginPercent = $netRevenue > 0
            ? round(($netProfit / $netRevenue) * 100, 1)
            : null;

        $summary['expenses'] = $expenseRows;
        $summary['total_expenses'] = $totalExpenses;
        $summary['net_profit'] = $netProfit;
        $summary['net_profit_margin_percent'] = $netProfitMarginPercent;

        return $summary;
    }
}
