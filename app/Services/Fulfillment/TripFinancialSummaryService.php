<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Expense;
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
        $netRevenue = round($netRevenue, 2);
        $totalProfit = round($netRevenue - $totalCost, 2);
        $profitMarginPercent = $netRevenue > 0
            ? round(($totalProfit / $netRevenue) * 100, 1)
            : null;

        return [
            'order_count' => $sales->count(),
            'total_amount' => $totalAmount,
            'net_revenue' => $netRevenue,
            'total_profit' => $totalProfit,
            'profit_margin_percent' => $profitMarginPercent,
            'expenses' => [],
            'total_expenses' => 0.0,
            'net_profit' => $totalProfit,
            'net_profit_margin_percent' => $profitMarginPercent,
        ];
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
