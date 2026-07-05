<?php

namespace App\Services\Fulfillment;

use App\Models\CustomerReturn;
use App\Models\DispatchTrip;
use App\Models\Sale;
use Illuminate\Support\Collection;

class TripCodService
{
    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    public function balanceDue(Sale $sale): float
    {
        return max(0, round((float) $sale->order_total - (float) $sale->amount_paid, 2));
    }

    /** @param  array<string, mixed>  $settings */
    public function expectedAtDepart(Collection $sales, array $settings): float
    {
        if (empty($settings['enable_cod_reconciliation'])) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($sales as $sale) {
            if ($this->isCreditSale($sale)) {
                continue;
            }

            $total += $this->balanceDue($sale);
        }

        return round($total, 2);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, float>  $returnAmounts
     */
    public function outstandingFromOrders(Collection $sales, array $settings, array $returnAmounts = []): float
    {
        if (empty($settings['enable_cod_reconciliation'])) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($sales as $sale) {
            if ($this->isCreditSale($sale)) {
                continue;
            }

            $balance = $this->balanceDue($sale);
            $returnAmount = round((float) ($returnAmounts[(int) $sale->id] ?? 0), 2);
            $total += $this->isFailedDelivery($sale) ? 0 : max(0, $balance - $returnAmount);
        }

        return round($total, 2);
    }

    /** @param  array<string, mixed>  $settings */
    public function outstandingFromTrip(DispatchTrip $trip, array $settings): float
    {
        $trip->loadMissing('sales');
        $saleIds = $trip->sales->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->outstandingFromOrders(
            $trip->sales,
            $settings,
            $this->returnAmountsBySale($saleIds),
        );
    }

    public function isCreditSale(Sale $sale): bool
    {
        return (bool) $sale->is_credit_sale;
    }

    public function isFailedDelivery(Sale $sale): bool
    {
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $isDelivered = in_array((string) $sale->status, self::DELIVERED_STATUSES, true);
        $isCancelled = (string) $sale->status === 'cancelled';
        $deliveryOutcome = (string) ($meta['driver_delivery_outcome'] ?? '');

        return ($deliveryOutcome === 'failed' || $isCancelled) && ! $isDelivered;
    }

    /**
     * @param  list<int>  $saleIds
     * @return array<int, float>
     */
    public function returnAmountsBySale(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if ($saleIds === []) {
            return [];
        }

        return CustomerReturn::query()
            ->whereIn('sale_id', $saleIds)
            ->whereIn('status', ['pending', 'approved'])
            ->select('sale_id', \Illuminate\Support\Facades\DB::raw('SUM(total_amount) as total_returned'))
            ->groupBy('sale_id')
            ->pluck('total_returned', 'sale_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }
}
