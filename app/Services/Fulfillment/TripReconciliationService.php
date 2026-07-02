<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\User;

class TripReconciliationService
{
    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    public function __construct(
        protected DispatchTripService $trips,
        protected TripFinancialSummaryService $financials,
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
        $podPendingCount = 0;
        $expectedFromOrders = 0.0;

        foreach ($trip->sales as $sale) {
            $balance = max(0, round((float) $sale->order_total - (float) $sale->amount_paid, 2));
            $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
            $isDelivered = in_array((string) $sale->status, self::DELIVERED_STATUSES, true);
            $podCaptured = ! empty($meta['pod_captured']);

            if ($isDelivered) {
                $deliveredCount++;
            }
            if ($isDelivered && ! empty($settings['require_pod_on_delivered']) && ! $podCaptured) {
                $podPendingCount++;
            }

            if (! empty($settings['enable_cod_reconciliation'])) {
                $expectedFromOrders += $balance;
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
                'is_credit_sale' => (bool) $sale->is_credit_sale,
                'pod_captured' => $podCaptured,
                'is_delivered' => $isDelivered,
            ];
        }

        usort($orders, fn ($a, $b) => ($a['stop_seq'] <=> $b['stop_seq']) ?: ($a['id'] <=> $b['id']));

        $orderCount = count($orders);
        $codEnabled = ! empty($settings['enable_cod_reconciliation']);
        $requireSettlement = $codEnabled && ! empty($settings['require_trip_cash_settlement']);
        $requirePod = ! empty($settings['require_pod_on_delivered']);

        $expectedCash = $codEnabled
            ? round((float) ($trip->expected_cash ?? $this->trips->computeExpectedCash($trip, $settings)), 2)
            : 0.0;
        $collectedCash = $trip->collected_cash !== null ? round((float) $trip->collected_cash, 2) : null;
        $variance = $trip->cash_variance !== null ? round((float) $trip->cash_variance, 2) : null;

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
                'label' => 'Complete deliveries',
                'done' => $orderCount === 0 || $deliveredCount === $orderCount,
                'required' => $orderCount > 0,
                'detail' => "{$deliveredCount} of {$orderCount} delivered",
            ],
            [
                'id' => 'pod',
                'label' => 'Capture proof of delivery',
                'done' => ! $requirePod || ($orderCount > 0 && $podPendingCount === 0 && $deliveredCount === $orderCount),
                'required' => $requirePod && $orderCount > 0,
                'detail' => $requirePod
                    ? ($podPendingCount > 0 ? "{$podPendingCount} missing POD" : 'All POD captured')
                    : 'Not required',
            ],
            [
                'id' => 'settle',
                'label' => 'Record cash settlement',
                'done' => ! $requireSettlement || (bool) $trip->settled_at,
                'required' => $requireSettlement && $expectedCash > 0,
                'detail' => $trip->settled_at ? 'Cash recorded' : ($expectedCash > 0 ? 'KES '.number_format($expectedCash, 2).' expected' : 'No COD due'),
            ],
            [
                'id' => 'close',
                'label' => 'Close trip',
                'done' => $trip->status === 'completed',
                'required' => true,
                'detail' => $trip->completed_at ? "Closed {$trip->completed_at}" : null,
            ],
        ];

        $blockers = $this->blockers($trip, $settings, $lineCount, $loadingLocked, $orderCount, $deliveredCount, $podPendingCount, $expectedCash);

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
                'pending_count' => max(0, $orderCount - $deliveredCount),
                'pod_pending_count' => $podPendingCount,
            ],
            'cash' => [
                'expected_cash' => $expectedCash,
                'expected_from_orders' => round($expectedFromOrders, 2),
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
                && ! $trip->settled_at
                && $expectedCash >= 0,
            'can_complete' => $trip->status === 'in_transit' && $blockers === [],
            'blockers' => $blockers,
            'financial_summary' => $this->financials->summarizeForTrip($trip),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    protected function blockers(
        DispatchTrip $trip,
        array $settings,
        int $lineCount,
        bool $loadingLocked,
        int $orderCount,
        int $deliveredCount,
        int $podPendingCount,
        float $expectedCash,
    ): array {
        if ($trip->status !== 'in_transit') {
            return ['Trip must be in transit before closing.'];
        }

        $blockers = [];

        if ($lineCount > 0 && ! $loadingLocked) {
            $blockers[] = 'Lock the loading list before closing the trip.';
        }

        if ($orderCount > 0 && $deliveredCount < $orderCount) {
            $blockers[] = 'All orders on this trip must be marked delivered or completed.';
        }

        if (! empty($settings['require_pod_on_delivered']) && $podPendingCount > 0) {
            $blockers[] = 'Proof of delivery is required for all delivered orders.';
        }

        if (
            ! empty($settings['enable_cod_reconciliation'])
            && ! empty($settings['require_trip_cash_settlement'])
            && ! $trip->settled_at
            && $expectedCash > 0
        ) {
            $blockers[] = 'Record cash settlement before closing the trip.';
        }

        return $blockers;
    }
}
