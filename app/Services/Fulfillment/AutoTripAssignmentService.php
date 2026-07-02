<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\RouteOrderScope;
use Illuminate\Support\Facades\Log;

class AutoTripAssignmentService
{
    public function __construct(
        protected DispatchTripService $dispatchTrips,
        protected ErpContext $erp,
    ) {}

    /** Assign a sale to today's draft trip for its route when auto-trips are enabled. */
    public function tryAssignSale(Sale $sale, User $user): ?DispatchTrip
    {
        try {
            return $this->assignSaleIfEligible($sale, $user);
        } catch (\Throwable $e) {
            Log::warning('Auto trip assignment skipped', [
                'sale_id' => $sale->id,
                'order_num' => $sale->order_num,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function assignSaleIfEligible(Sale $sale, User $user): ?DispatchTrip
    {
        $gate = $this->erp->gateForUser($user);
        if (! $gate->distributionOpsEnabled()) {
            return null;
        }

        $settings = $gate->distributionSettings();
        if (empty($settings['auto_create_trips'])) {
            return null;
        }

        $includeNormalOrders = RouteOrderScope::includeNormalOrders($settings);
        if (! RouteOrderScope::eligibleForLoadingList($sale, $includeNormalOrders)) {
            return null;
        }

        if (! $sale->route_id || ! $sale->branch_id) {
            return null;
        }

        if (in_array($sale->status, ['cancelled', 'expired'], true)) {
            return null;
        }

        $assignStatus = (string) ($settings['assign_on_status'] ?? 'processed');
        $workflow = OrderWorkflowService::forGate($gate);
        if (! $workflow->isAtOrPastStatus((string) $sale->status, $assignStatus, (string) $sale->channel)) {
            return null;
        }

        $existingTripId = (int) (($sale->fulfillment_meta ?? [])['trip_id'] ?? 0);
        if ($existingTripId > 0) {
            $existing = DispatchTrip::query()->find($existingTripId);
            if ($existing && ! in_array($existing->status, ['completed', 'cancelled'], true)) {
                return $existing;
            }
        }

        $scheduledDate = $sale->required_date
            ? date('Y-m-d', strtotime((string) $sale->required_date))
            : now()->toDateString();

        $trip = DispatchTrip::query()
            ->where('branch_id', $sale->branch_id)
            ->where('route_id', $sale->route_id)
            ->whereDate('scheduled_date', $scheduledDate)
            ->whereIn('status', ['draft', 'loading'])
            ->orderBy('id')
            ->first();

        if (! $trip) {
            $trip = $this->dispatchTrips->createTrip($user, [
                'branch_id' => (int) $sale->branch_id,
                'route_id' => (int) $sale->route_id,
                'scheduled_date' => $scheduledDate,
            ]);
        }

        return $this->dispatchTrips->assignOrders($trip, [(int) $sale->id], $user);
    }
}
