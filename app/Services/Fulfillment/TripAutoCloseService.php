<?php

namespace App\Services\Fulfillment;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use InvalidArgumentException;

class TripAutoCloseService
{
    public function __construct(
        protected DispatchTripService $trips,
        protected ErpContext $erp,
    ) {}

    public function syncSaleAfterAccountingPayment(
        Sale $sale,
        User $user,
        float $paymentAmount,
        ?float $invoicePaidTotal = null,
        ?int $paymentMethodId = null,
    ): Sale {
        $sale->refresh();

        $orderTotal = round((float) $sale->order_total, 2);
        $paidTotal = max(
            (float) $sale->amount_paid,
            round((float) $sale->amount_paid + $paymentAmount, 2),
            $invoicePaidTotal !== null ? round($invoicePaidTotal, 2) : 0.0,
        );
        $paidTotal = $orderTotal > 0 ? min($orderTotal, $paidTotal) : 0.0;

        $gate = $this->erp->gateForUser($user);
        $workflow = OrderWorkflowService::forGate($gate);
        $channel = $workflow->normalizeSalesChannel((string) ($sale->channel ?: 'backend'));
        $method = $paymentMethodId ? PaymentMethod::query()->find($paymentMethodId) : null;
        $paymentMethodCode = $method?->method_code ?? 'CASH';
        $fullyPaid = $orderTotal <= 0 || $paidTotal + 0.01 >= $orderTotal;

        $updates = [
            'amount_paid' => $paidTotal,
            'payment_status' => $this->paymentStatusForAmounts($orderTotal, $paidTotal),
        ];

        if (! in_array((string) $sale->status, ['cancelled', 'held'], true)) {
            $orderStatus = $workflow->resolveStatusAfterPayment(
                $channel,
                (string) $sale->status,
                $paidTotal,
                $orderTotal,
                (bool) $sale->is_credit_sale,
                $paymentMethodCode,
                ! empty($gate->moduleSettings('sales')['allow_credit_pay_now']),
            );

            if ($fullyPaid && in_array((string) $sale->status, ['delivered', 'completed'], true)) {
                $orderStatus = $workflow->pickEnabledStatus('completed', $workflow->forChannel($channel));
            }

            $updates['status'] = $orderStatus;
            if ($orderStatus === 'completed' || $workflow->isTerminalStatus($orderStatus, $channel)) {
                $updates['completed_at'] = $sale->completed_at ?? now();
            }
        }

        $sale->update($updates);

        $sale = $sale->fresh();
        $this->tryAutoCloseTripsForSale($sale, $user);

        return $sale;
    }

    public function tryAutoCloseTripsForSale(Sale $sale, User $user): void
    {
        $trips = $sale->dispatchTrips()
            ->where('dispatch_trips.status', 'in_transit')
            ->with('sales')
            ->get();

        foreach ($trips as $trip) {
            if (! $this->allTripOrdersComplete($trip->sales)) {
                continue;
            }

            if ($this->tripBalanceDue($trip->sales) > 0.01) {
                continue;
            }

            try {
                $this->trips->completeTrip($trip, $user);
            } catch (InvalidArgumentException) {
                // Other reconciliation requirements, like POD, still block auto-close.
            }
        }
    }

    public function markReturnedSaleCompleteIfBalanced(Sale $sale, User $user): Sale
    {
        $sale->refresh();
        $orderTotal = round((float) $sale->order_total, 2);
        $amountPaid = min($orderTotal, round((float) $sale->amount_paid, 2));
        $paymentStatus = $this->paymentStatusForAmounts($orderTotal, $amountPaid);

        $updates = [
            'amount_paid' => $amountPaid,
            'payment_status' => $paymentStatus,
        ];

        if ($orderTotal <= 0.01 && ! in_array((string) $sale->status, ['cancelled', 'held'], true)) {
            $updates['status'] = 'completed';
            $updates['completed_at'] = $sale->completed_at ?? now();
        }

        $sale->update($updates);

        $sale = $sale->fresh();
        $this->tryAutoCloseTripsForSale($sale, $user);

        return $sale;
    }

    protected function paymentStatusForAmounts(float $orderTotal, float $amountPaid): string
    {
        if ($orderTotal <= 0.01) {
            return 'paid';
        }

        if ($amountPaid <= 0.01) {
            return 'unpaid';
        }

        return $amountPaid + 0.01 >= $orderTotal ? 'paid' : 'partial';
    }

    protected function allTripOrdersComplete($sales): bool
    {
        if ($sales->isEmpty()) {
            return false;
        }

        foreach ($sales as $sale) {
            if ((string) $sale->status !== 'completed') {
                return false;
            }
        }

        return true;
    }

    protected function tripBalanceDue($sales): float
    {
        $balance = 0.0;

        foreach ($sales as $sale) {
            $balance += max(0, round((float) $sale->order_total - (float) $sale->amount_paid, 2));
        }

        return round($balance, 2);
    }
}
