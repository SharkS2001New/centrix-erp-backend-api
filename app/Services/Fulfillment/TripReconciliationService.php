<?php

namespace App\Services\Fulfillment;

use App\Models\CustomerReturn;
use App\Models\DispatchTrip;
use App\Models\User;

class TripReconciliationService
{
    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    public function __construct(
        protected DispatchTripService $trips,
        protected TripFinancialSummaryService $financials,
        protected TripCodService $tripCod,
    ) {}

    /** @return array<string, mixed> */
    public function build(DispatchTrip $trip, User $user): array
    {
        $settings = app(\App\Services\Erp\ErpContext::class)
            ->gateForUser($user)
            ->distributionSettings();

        $trip->load(['route', 'driver', 'vehicle', 'sales.customer', 'loadingList.lines']);

        $loadingList = $trip->loadingList;
        $lineCount = $loadingList?->lines?->count() ?? 0;
        $loadingLocked = $loadingList && $loadingList->status !== 'open';

        $orders = [];
        $deliveredCount = 0;
        $failedCount = 0;
        $partialCount = 0;
        $resolvedCount = 0;
        $podPendingCount = 0;
        $expectedFromOrders = 0.0;
        $returnAmounts = $this->tripCod->returnAmountsBySale(
            $trip->sales->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        $returnNumbers = $this->returnNumbersBySale(
            $trip->sales->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        foreach ($trip->sales as $sale) {
            $balance = $this->tripCod->balanceDue($sale);
            $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
            $isDelivered = in_array((string) $sale->status, self::DELIVERED_STATUSES, true);
            $isCancelled = (string) $sale->status === 'cancelled';
            $deliveryOutcome = (string) ($meta['driver_delivery_outcome'] ?? '');
            $returnAmount = round((float) ($returnAmounts[(int) $sale->id] ?? 0), 2);
            $returnNo = $meta['driver_return_no'] ?? $returnNumbers[(int) $sale->id] ?? null;
            $hasReturn = $returnAmount > 0.01 || $returnNo !== null;
            $isFailed = $this->tripCod->isFailedDelivery($sale);
            $isPartial = $deliveryOutcome === 'partial';
            $isFullDelivered = $isDelivered && ! $isPartial;
            $isResolved = $isFullDelivered
                || ($isPartial && $isDelivered && $hasReturn)
                || ($isCancelled && $hasReturn);
            $podCaptured = ! empty($meta['pod_captured']);

            if ($isFullDelivered) {
                $deliveredCount++;
            }
            if ($isFailed) {
                $failedCount++;
            }
            if ($isPartial) {
                $partialCount++;
            }
            if ($isResolved) {
                $resolvedCount++;
            }
            if ($isDelivered && ! empty($settings['require_pod_on_delivered']) && ! $podCaptured) {
                $podPendingCount++;
            }

            if (! empty($settings['enable_cod_reconciliation']) && ! $this->tripCod->isCreditSale($sale)) {
                $expectedFromOrders += $isFailed ? 0 : max(0, $balance - $returnAmount);
            }

            $orders[] = [
                'id' => $sale->id,
                'order_num' => $sale->order_num,
                'customer_name' => $sale->customer_name_override
                    ?: ($sale->customer?->customer_name ?? null),
                'status' => $sale->status,
                'stop_seq' => (int) ($sale->pivot?->stop_seq ?? 0),
                'order_total' => round((float) $sale->order_total, 2),
                'amount_paid' => round((float) $sale->amount_paid, 2),
                'balance_due' => $balance,
                'return_amount' => $returnAmount,
                'return_no' => $returnNo,
                'is_credit_sale' => (bool) $sale->is_credit_sale,
                'pod_captured' => $podCaptured,
                'is_delivered' => $isDelivered,
                'is_full_delivered' => $isFullDelivered,
                'is_cancelled' => $isCancelled,
                'is_failed_delivery' => $isFailed,
                'is_partial_delivery' => $isPartial,
                'is_resolved' => $isResolved,
                'delivery_outcome' => $deliveryOutcome ?: null,
                'delivery_reason' => $meta['driver_delivery_reason'] ?? null,
            ];
        }

        usort($orders, fn ($a, $b) => ($a['stop_seq'] <=> $b['stop_seq']) ?: ($a['id'] <=> $b['id']));

        $orderCount = count($orders);
        $codEnabled = ! empty($settings['enable_cod_reconciliation']);
        $requireSettlement = $codEnabled;
        $requirePod = ! empty($settings['require_pod_on_delivered']);

        $expectedCash = $codEnabled ? round($expectedFromOrders, 2) : 0.0;
        $outstandingCash = $expectedCash;
        $collectedCash = $trip->collected_cash !== null ? round((float) $trip->collected_cash, 2) : null;
        $variance = $collectedCash !== null ? round($collectedCash - $outstandingCash, 2) : null;

        $steps = [
            [
                'id' => 'loading_list',
                'label' => 'Lock loading list',
                'done' => $lineCount === 0 || $loadingLocked,
                'required' => $lineCount > 0,
                'detail' => $lineCount === 0
                    ? 'No products to load'
                    : ($loadingLocked ? 'Loading list locked' : 'Prepared / checked names required'),
            ],
            [
                'id' => 'depart',
                'label' => 'Start trip',
                'done' => in_array($trip->status, ['in_transit', 'completed'], true),
                'required' => true,
                'detail' => $trip->departed_at ? "Departed {$trip->departed_at}" : null,
            ],
            [
                'id' => 'deliveries',
                'label' => 'Resolve deliveries',
                'done' => $orderCount === 0 || $resolvedCount === $orderCount,
                'required' => $orderCount > 0,
                'detail' => "{$deliveredCount} full, {$partialCount} partial, {$failedCount} cancelled, ".max(0, $orderCount - $resolvedCount).' pending',
            ],
            [
                'id' => 'pod',
                'label' => 'Capture proof of delivery',
                'done' => ! $requirePod || ($orderCount > 0 && $podPendingCount === 0 && $resolvedCount === $orderCount),
                'required' => $requirePod && $orderCount > 0,
                'detail' => $requirePod
                    ? ($podPendingCount > 0 ? "{$podPendingCount} missing POD" : 'All POD captured')
                    : 'Not required',
            ],
            [
                'id' => 'settle',
                'label' => 'Record cash settlement',
                'done' => ! $requireSettlement || $outstandingCash <= 0.01 || ((bool) $trip->settled_at && abs((float) $variance) <= 0.01),
                'required' => $requireSettlement && $outstandingCash > 0.01,
                'detail' => $trip->settled_at
                    ? (abs((float) $variance) <= 0.01 ? 'Cash reconciled' : 'Cash variance KES '.number_format((float) $variance, 2))
                    : ($outstandingCash > 0.01 ? 'KES '.number_format($outstandingCash, 2).' outstanding' : 'All trip order payments settled'),
            ],
            [
                'id' => 'close',
                'label' => 'Close trip',
                'done' => $trip->status === 'completed',
                'required' => true,
                'detail' => $trip->completed_at ? "Closed {$trip->completed_at}" : null,
            ],
        ];

        $blockers = $this->blockers($trip, $settings, $lineCount, $loadingLocked, $orderCount, $resolvedCount, $podPendingCount, $outstandingCash, $orders);

        return [
            'trip' => [
                'id' => $trip->id,
                'trip_code' => $trip->trip_code,
                'status' => $trip->status,
                'scheduled_date' => $trip->scheduled_date,
                'departed_at' => $trip->departed_at,
                'completed_at' => $trip->completed_at,
                'route_name' => $trip->route?->route_name,
                'driver_name' => $trip->driver?->full_name,
                'vehicle_label' => $trip->vehicle?->plate_number ?? $trip->vehicle?->vehicle_name,
            ],
            'settings' => [
                'enable_cod_reconciliation' => $codEnabled,
                'require_trip_cash_settlement' => $requireSettlement,
                'require_pod_on_delivered' => $requirePod,
            ],
            'loading_list' => [
                'status' => $loadingList?->status ?? 'none',
                'line_count' => $lineCount,
                'total_amount' => round((float) ($loadingList?->total_amount ?? 0), 2),
                'prepared_by_name' => $loadingList?->prepared_by_name ?? $trip->prepared_by_name,
                'checked_by_name' => $loadingList?->checked_by_name ?? $trip->checked_by_name,
                'locked_at' => $loadingList?->locked_at,
            ],
            'delivery' => [
                'order_count' => $orderCount,
                'delivered_count' => $deliveredCount,
                'failed_count' => $failedCount,
                'partial_count' => $partialCount,
                'resolved_count' => $resolvedCount,
                'pending_count' => max(0, $orderCount - $resolvedCount),
                'pod_pending_count' => $podPendingCount,
            ],
            'cash' => [
                'expected_cash' => $expectedCash,
                'expected_from_orders' => round($expectedFromOrders, 2),
                'outstanding_from_orders' => $outstandingCash,
                'collected_cash' => $collectedCash,
                'cash_variance' => $variance,
                'settled_at' => $trip->settled_at,
                'settled_by' => $trip->settled_by,
            ],
            'orders' => $orders,
            'steps' => $steps,
            'can_start' => in_array($trip->status, ['draft', 'loading'], true)
                && $orderCount > 0
                && ($lineCount === 0 || $loadingLocked),
            'can_settle' => $codEnabled
                && in_array($trip->status, ['in_transit', 'completed'], true)
                && $outstandingCash > 0.01
                && (! $trip->settled_at || abs((float) $variance) > 0.01),
            'can_complete' => $trip->status === 'in_transit' && $blockers === [],
            'blockers' => $blockers,
            'financial_summary' => $this->financials->summarizeForTrip($trip),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $orders
     * @return list<string>
     */
    protected function blockers(
        DispatchTrip $trip,
        array $settings,
        int $lineCount,
        bool $loadingLocked,
        int $orderCount,
        int $resolvedCount,
        int $podPendingCount,
        float $outstandingCash,
        array $orders = [],
    ): array {
        if ($trip->status !== 'in_transit') {
            return ['Trip must be in transit before closing.'];
        }

        $blockers = [];

        if ($lineCount > 0 && ! $loadingLocked) {
            $blockers[] = 'Lock the loading list before closing the trip.';
        }

        if ($orderCount > 0 && $resolvedCount < $orderCount) {
            $blockers[] = 'All stops must be fully delivered, partially delivered with a return, or cancelled with a return before closing the trip.';
        }

        if ($this->unpaidResolvedOrderCount($orders) > 0) {
            $blockers[] = 'All delivered orders must be fully paid before closing the trip.';
        }

        if (! empty($settings['require_pod_on_delivered']) && $podPendingCount > 0) {
            $blockers[] = 'Proof of delivery is required for all delivered orders.';
        }

        if (
            ! empty($settings['enable_cod_reconciliation'])
            && $outstandingCash > 0.01
        ) {
            if (! $trip->settled_at) {
                $blockers[] = 'Record cash settlement before closing the trip.';
            } else {
                $collectedCash = round((float) ($trip->collected_cash ?? 0), 2);
                $variance = round($collectedCash - $outstandingCash, 2);
                if (abs($variance) > 0.01) {
                    $blockers[] = 'Cash settlement must match the final expected COD before closing the trip.';
                }
            }
        }

        return $blockers;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     */
    protected function unpaidResolvedOrderCount(array $orders): int
    {
        $unpaid = 0;

        foreach ($orders as $order) {
            if (empty($order['is_resolved']) || ! empty($order['is_failed_delivery'])) {
                continue;
            }

            if (! empty($order['is_credit_sale'])) {
                continue;
            }

            $balanceDue = (float) ($order['balance_due'] ?? 0);
            $returnAmount = (float) ($order['return_amount'] ?? 0);
            if ($balanceDue - $returnAmount > 0.01) {
                $unpaid++;
            }
        }

        return $unpaid;
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
            ->select('sale_id', \Illuminate\Support\Facades\DB::raw('SUM(total_amount) as total_returned'))
            ->groupBy('sale_id')
            ->pluck('total_returned', 'sale_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * @param  list<int>  $saleIds
     * @return array<int, string>
     */
    protected function returnNumbersBySale(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if ($saleIds === []) {
            return [];
        }

        return CustomerReturn::query()
            ->whereIn('sale_id', $saleIds)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('id')
            ->get(['sale_id', 'return_no'])
            ->unique('sale_id')
            ->mapWithKeys(fn (CustomerReturn $return) => [
                (int) $return->sale_id => (string) $return->return_no,
            ])
            ->all();
    }
}
