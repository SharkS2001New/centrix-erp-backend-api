<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Models\RouteSchedule;
use App\Services\Sales\OrderCancellationRequestService;
use App\Services\Sales\SaleCancellationService;
use App\Services\Fulfillment\AutoTripAssignmentService;
use App\Services\Fulfillment\FulfillmentNotificationService;
use App\Services\Fulfillment\LoadingListBuilder;
use App\Services\Fulfillment\PodService;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderWorkflowController extends Controller
{
    use HandlesBranchScope;
    use HandlesInventory;

    public function __construct(
        protected ErpContext $erp,
        protected LoadingListBuilder $loadingListBuilder,
    ) {}

    public function loadWeightStatus(Request $request, int $saleId)
    {
        $sale = $this->findScopedSale($saleId, $request->user());

        return response()->json($this->loadingListBuilder->saleWeightStatus($sale));
    }

    public function updateOrderProductWeights(Request $request, int $saleId)
    {
        $sale = $this->findScopedSale($saleId, $request->user());

        $entries = $request->input('weights');
        if (! is_array($entries) || $entries === []) {
            $entries = $request->input('products');
        }

        if (! is_array($entries) || $entries === []) {
            throw new InvalidArgumentException('Provide product weights to save.');
        }

        return response()->json(
            $this->loadingListBuilder->updateSaleProductWeights($sale, $entries, $request->user()),
        );
    }

    public function transition(Request $request, int $saleId)
    {
        $data = $request->validate([
            'status' => 'required|string',
            'fulfillment_meta' => 'nullable|array',
        ]);

        $sale = $this->findScopedSale($saleId, $request->user());
        $gate = $this->erp->gateForUser($request->user());
        $updated = $this->transitionSale(
            $sale,
            $data['status'],
            $request->user(),
            $data['fulfillment_meta'] ?? [],
            $gate,
        );

        return response()->json($updated);
    }

    /** Public wrapper for internal/mobile delivery flows. */
    public function transitionSaleForUser(
        Sale $sale,
        string $toStatus,
        User $user,
        array $meta = [],
    ): Sale {
        return $this->transitionSale(
            $sale,
            $toStatus,
            $user,
            $meta,
            $this->erp->gateForUser($user),
        );
    }

    public function requestCancellation(Request $request, int $saleId)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $sale = $this->findScopedSale($saleId, $request->user());
        $gate = $this->erp->gateForUser($request->user());

        $actionRequest = app(OrderCancellationRequestService::class)->requestCancellation(
            $request->user(),
            $sale,
            $data['reason'],
            $gate,
        );

        return response()->json([
            'message' => 'Cancellation request submitted for manager approval.',
            'pending_approval' => true,
            'action_request_id' => (int) $actionRequest->id,
        ], 202);
    }

    protected function applyFulfillmentMeta(Sale $sale, string $status, array $meta, CapabilityGate $gate): Sale
    {
        $distributionSettings = $gate->distributionSettings();
        $distributionEnabled = $gate->distributionOpsEnabled();

        $assignOnStatus = (string) ($distributionSettings['assign_on_status'] ?? 'processed');
        if ($distributionEnabled && $status === $assignOnStatus && ! empty($distributionSettings['require_weight_on_load'])) {
            $this->loadingListBuilder->assertSaleHasLoadWeight($sale);
        }

        if ($distributionEnabled && empty($meta['driver_id']) && ! empty($distributionSettings['auto_assign_driver'])) {
            $driver = $this->resolveAutoDriver($sale);
            if ($driver) {
                $meta['driver_id'] = $driver->id;
                if (empty($meta['vehicle_id']) && ! empty($distributionSettings['auto_assign_truck']) && $driver->default_vehicle_id) {
                    $meta['vehicle_id'] = $driver->default_vehicle_id;
                }
            }
        }

        $meta = array_merge($sale->fulfillment_meta ?? [], $meta);
        $updates = ['fulfillment_meta' => $meta];

        if (! empty($meta['driver_id'])) {
            $driver = Driver::find($meta['driver_id']);
            if ($driver) {
                if (empty($meta['vehicle_id']) && $driver->default_vehicle_id) {
                    $meta['vehicle_id'] = $driver->default_vehicle_id;
                    $updates['fulfillment_meta'] = $meta;
                }
                if (! $sale->route_id && $driver->default_route_id) {
                    $updates['route_id'] = $driver->default_route_id;
                }
            }
        }

        $sale->update($updates);

        return $sale->fresh();
    }

    protected function transitionSale(
        Sale $sale,
        string $toStatus,
        User $user,
        array $meta = [],
        ?CapabilityGate $gate = null,
    ): Sale {
        $gate ??= $this->erp->gateForUser($user);
        $workflow = OrderWorkflowService::forGate($gate);
        $from = (string) $sale->status;
        $toStatus = (string) $toStatus;

        if ($from === $toStatus) {
            if ($this->hasFulfillmentUpdate($meta)) {
                return $this->applyFulfillmentMeta($sale, $toStatus, $meta, $gate);
            }

            throw new InvalidArgumentException(
                "Order is already marked as {$this->humanStatusLabel($toStatus)}.",
            );
        }

        if ($from === 'editable' && $toStatus === 'booked') {
            throw new InvalidArgumentException(
                'Editable orders must be resubmitted for approval before they can be booked.',
            );
        }

        if (! $workflow->canTransition($from, $toStatus, $sale->channel)) {
            $allowed = array_values(array_filter(
                $workflow->allowedTransitions($from, $sale->channel),
                fn (string $status) => $status !== $from && $status !== 'cancelled',
            ));
            $hint = $allowed !== []
                ? ' Allowed next steps: '.implode(', ', array_map([$this, 'humanStatusLabel'], $allowed)).'.'
                : '';

            throw new InvalidArgumentException(
                "Cannot move order from {$this->humanStatusLabel($from)} to {$this->humanStatusLabel($toStatus)}.{$hint}",
            );
        }

        $balanceDue = max(0, (float) $sale->order_total - (float) $sale->amount_paid);
        if ($balanceDue > 0.01) {
            if ($toStatus === 'paid') {
                throw new InvalidArgumentException(
                    'Collect payment before marking this order as paid.',
                );
            }
            if ($toStatus === 'pending_payment' && (float) $sale->amount_paid <= 0) {
                throw new InvalidArgumentException(
                    'Record a payment before marking this order as partially paid.',
                );
            }
            if ($toStatus === 'completed') {
                throw new InvalidArgumentException(
                    'Collect the outstanding balance before marking this order as completed.',
                );
            }
        }

        if ($toStatus === 'cancelled') {
            app(SaleCancellationService::class)->cancelSale($sale, $user, $gate);

            app(\App\Services\Notifications\ActionRequestService::class)->markResolvedFromDomain(
                'order_cancel',
                'sale',
                (int) $sale->id,
                'approved',
                $user,
            );

            $sale = $sale->fresh();
            $this->notifyWorkflowTransition($sale, $from, 'cancelled', $user);

            return $sale;
        }

        if ($toStatus === 'expired') {
            DB::transaction(function () use ($sale, $user, $gate) {
                $this->restoreCancelledSaleStock($sale, $user);

                $sale->update([
                    'status' => 'expired',
                    'expired_at' => now(),
                    'expired_by' => $user->id,
                    'stock_balanced' => 0,
                ]);

                app(ReferenceJournalReversalService::class)->reverseIfEnabled(
                    'sale',
                    (int) $sale->id,
                    $user,
                    $gate,
                );

                app(\App\Services\Notifications\ActionRequestService::class)->cancelAllPendingForSale(
                    $sale->fresh(),
                    $user,
                    'Order expired.',
                );
            });

            $sale = $sale->fresh();
            $this->notifyWorkflowTransition($sale, $from, 'expired', $user);

            return $sale;
        }

        $salesSettings = $gate->moduleSettings('sales');
        $distributionSettings = $gate->distributionSettings();
        $distributionEnabled = $gate->distributionOpsEnabled();
        $assignOnStatus = (string) ($distributionSettings['assign_on_status'] ?? 'processed');

        $updates = ['status' => $toStatus];

        if ($distributionEnabled && in_array($toStatus, ['processed', 'delivered', 'completed'], true)) {
            if ($toStatus === $assignOnStatus && ! empty($distributionSettings['require_weight_on_load'])) {
                $this->loadingListBuilder->assertSaleHasLoadWeight($sale);
            }

            if (empty($meta['driver_id']) && ! empty($distributionSettings['auto_assign_driver'])) {
                $driver = $this->resolveAutoDriver($sale);
                if ($driver) {
                    $meta['driver_id'] = $driver->id;
                    if (empty($meta['vehicle_id']) && ! empty($distributionSettings['auto_assign_truck']) && $driver->default_vehicle_id) {
                        $meta['vehicle_id'] = $driver->default_vehicle_id;
                    }
                }
            }

            if (
                $toStatus === 'delivered'
                && ! empty($distributionSettings['require_pod_on_delivered'])
                && empty($meta['pod_captured'])
                && empty(($sale->fulfillment_meta ?? [])['pod_captured'])
                && ! app(PodService::class)->hasPod($sale)
            ) {
                throw new InvalidArgumentException('Proof of delivery is required before marking this order as delivered.');
            }

            $meta = array_merge($sale->fulfillment_meta ?? [], $meta);

            if (! empty($meta['driver_id'])) {
                $driver = Driver::find($meta['driver_id']);
                if ($driver) {
                    if (empty($meta['vehicle_id']) && $driver->default_vehicle_id) {
                        $meta['vehicle_id'] = $driver->default_vehicle_id;
                    }
                    if (! $sale->route_id && $driver->default_route_id) {
                        $updates['route_id'] = $driver->default_route_id;
                    }
                }
            }

            $updates['fulfillment_meta'] = $meta;
        }

        $deliveryOn = (string) ($distributionSettings['set_delivery_date_on'] ?? 'delivered');
        if ($distributionEnabled && $toStatus === $deliveryOn) {
            $updates['delivery_date'] = now();
        }

        if ($workflow->isTerminalStatus($toStatus, (string) $sale->channel)) {
            $updates['completed_at'] = $sale->completed_at ?? now();
        }

        if ($gate->shouldDeductStockOnWorkflowTransition($workflow, $toStatus, (string) $sale->channel) && ! $sale->stock_balanced) {
            $this->deductSaleStockIfNeeded($sale, $user);
        } elseif ($gate->shouldReserveStockOnTransition($workflow, $toStatus, (string) $sale->channel) && ! $sale->stock_balanced) {
            $this->reserveSaleStockIfNeeded($sale, $user, $gate);
        }

        $sale->update($updates);
        $sale = $sale->fresh();

        if ($distributionEnabled && $toStatus === $assignOnStatus) {
            app(AutoTripAssignmentService::class)->tryAssignSale($sale, $user);
            $sale = $sale->fresh();
        }

        $this->notifyWorkflowTransition($sale, $from, $toStatus, $user);

        return $sale;
    }

    protected function notifyWorkflowTransition(Sale $sale, string $from, string $to, User $user): void
    {
        app(AdminNotificationService::class)->notifyPermission($user, 'sales.manage', [
            'type' => 'info',
            'severity' => in_array($to, ['cancelled', 'expired'], true) ? 'warning' : 'default',
            'title' => 'Order status changed',
            'message' => "Order #{$sale->order_num} moved from {$from} to {$to} by ".($user->full_name ?: $user->username).'.',
            'action_url' => "/sales/orders/{$sale->id}",
        ], InAppNotificationEvents::ORDER_STATUS_CHANGE);

        if ($to !== 'delivered') {
            return;
        }

        $organization = Organization::query()->find((int) $sale->organization_id);
        if ($organization) {
            app(FulfillmentNotificationService::class)->notifyOrderDelivered($sale, $organization);
        }
    }

    protected function resolveAutoDriver(Sale $sale): ?Driver
    {
        $query = Driver::query()->where('is_active', true);
        if ($sale->branch_id) {
            $query->where('branch_id', $sale->branch_id);
        }

        if ($sale->route_id) {
            $date = $sale->required_date ?? $sale->created_at;
            if ($date) {
                $dayOfWeek = (int) date('w', strtotime((string) $date));
                $schedule = RouteSchedule::query()
                    ->where('branch_id', $sale->branch_id)
                    ->where('route_id', $sale->route_id)
                    ->where('day_of_week', $dayOfWeek)
                    ->where('is_active', true)
                    ->whereNotNull('default_driver_id')
                    ->first();
                if ($schedule?->default_driver_id) {
                    $scheduled = Driver::query()
                        ->where('id', $schedule->default_driver_id)
                        ->where('is_active', true)
                        ->first();
                    if ($scheduled) {
                        return $scheduled;
                    }
                }
            }

            $match = (clone $query)->where('default_route_id', $sale->route_id)->orderBy('id')->first();
            if ($match) {
                return $match;
            }
        }

        return $query->orderBy('id')->first();
    }

    protected function deductSaleStockIfNeeded(Sale $sale, User $user): void
    {
        if ($sale->stock_balanced) {
            return;
        }

        $inventorySettings = $this->erp->gateForUser($user)->moduleSettings('inventory');
        $salesSettings = $this->erp->gateForUser($user)->moduleSettings('sales');
        $txnType = $this->saleTransactionType($sale->channel);
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);

        foreach ($sale->items ?? SaleItem::where('sale_id', $sale->id)->get() as $item) {
            $product = $this->orgProduct((int) $user->organization_id, (string) $item->product_code);
            $location = $product
                ? $this->resolveSaleLineStockLocation(
                    (string) $sale->channel,
                    $inventorySettings,
                    $salesSettings,
                    $product,
                    (bool) $item->on_wholesale_retail,
                )
                : $this->saleLineStockLocation(
                    (string) $sale->channel,
                    $inventorySettings,
                    $salesSettings,
                    (bool) $item->on_wholesale_retail,
                );

            $unitCost = max(0, (float) ($product?->last_cost_price ?? 0));

            $this->postStockLedger([
                'branch_id' => $sale->branch_id,
                'product_code' => $item->product_code,
                'stock_location' => $location,
                'transaction_type' => $txnType,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'quantity_change' => -abs((float) $item->quantity),
                'unit_cost' => $unitCost > 0 ? $unitCost : null,
                'created_by' => $user->id,
            ], $allowBelowStock);
        }

        $sale->update(['stock_balanced' => 1]);
        $this->releaseSaleReservations((int) $sale->id);
    }

    protected function humanStatusLabel(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    protected function hasFulfillmentUpdate(array $meta): bool
    {
        if ($meta === []) {
            return false;
        }

        foreach (['driver_id', 'vehicle_id', 'pod_captured', 'pod_signer_name', 'trip_id'] as $key) {
            if (! empty($meta[$key])) {
                return true;
            }
        }

        return false;
    }
}
