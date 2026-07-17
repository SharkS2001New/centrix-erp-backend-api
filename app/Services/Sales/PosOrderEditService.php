<?php

namespace App\Services\Sales;

use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use InvalidArgumentException;

class PosOrderEditService
{
    public function allowsPreviousDayMobileMutation(Sale $sale, ?CapabilityGate $gate = null): bool
    {
        if (($sale->channel ?? '') !== 'mobile') {
            return true;
        }

        $status = (string) $sale->status;
        if ($gate) {
            return OrderWorkflowService::forGate($gate)->isEditableLineStatus($status, 'mobile');
        }

        return in_array($status, OrderWorkflowService::EDITABLE_LINE_STATUSES, true);
    }

    public function blocksPreviousDayMobileMutation(Sale $sale, ?CapabilityGate $gate = null): bool
    {
        return ($sale->channel ?? '') === 'mobile'
            && ! $sale->created_at?->isSameDay(now())
            && ! $this->allowsPreviousDayMobileMutation($sale, $gate);
    }

    public function __construct(
        protected CustomerReturnService $customerReturnService,
        protected UserPermissionService $permissions,
    ) {}

    public function posOrderEditEnabled(CapabilityGate $gate): bool
    {
        return (bool) ($gate->moduleSettings('sales')['enable_pos_order_edit'] ?? false);
    }

    public function allowsCheckoutReEdit(string $channel, CapabilityGate $gate): bool
    {
        $channel = OrderWorkflowService::forGate($gate)->normalizeSalesChannel($channel);

        return match ($channel) {
            'pos' => $this->posOrderEditEnabled($gate),
            'mobile', 'backend', 'backoffice' => true,
            default => false,
        };
    }

    /** @return list<string> */
    public function editableStatusesForChannel(string $channel, CapabilityGate $gate): array
    {
        $workflowService = OrderWorkflowService::forGate($gate);
        $normalized = $workflowService->normalizeSalesChannel($channel);

        return $workflowService->restorableToCartStatuses(
            $normalized,
            $this->allowsCheckoutReEdit($normalized, $gate),
        );
    }

    public function assertSaleEditable(Sale $sale, User $user, CapabilityGate $gate): void
    {
        if ($sale->status === 'cancelled' || (int) ($sale->archived ?? 0) === 1) {
            throw new InvalidArgumentException('This order cannot be edited.');
        }

        if ((bool) (($sale->fulfillment_meta ?? [])['legacy_import'] ?? false)) {
            throw new InvalidArgumentException('Legacy materialized orders cannot be edited from POS.');
        }

        if ((int) $sale->cashier_id !== (int) $user->id
            && ! $this->permissions->canEditOthersSalesOrders($user, $gate)) {
            throw new InvalidArgumentException('You can only re-edit your own orders.');
        }

        $channel = $sale->channel ?: 'pos';
        $workflowService = OrderWorkflowService::forGate($gate);
        $status = (string) $sale->status;
        $allowed = $workflowService->editOrderStatuses();

        if (in_array($status, $allowed, true)) {
            return;
        }

        $aligned = $workflowService->alignStatusToPipeline($status, $channel);
        if (in_array($aligned, $allowed, true)) {
            return;
        }

        throw new InvalidArgumentException('This order cannot be edited in its current status.');
    }

    public function canRestoreSaleToCart(Sale $sale, User $user, CapabilityGate $gate): bool
    {
        try {
            $this->assertSaleEditable($sale, $user, $gate);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Issue a fiscal credit note for a completed POS sale before it is cancelled for re-edit.
     * Stock reversal is handled separately by restore-to-cart.
     */
    public function fiscalVoidBeforeEdit(Sale $sale, User $user, CapabilityGate $gate): void
    {
        if (! $this->posOrderEditEnabled($gate)) {
            return;
        }

        if (OrderWorkflowService::forGate($gate)->normalizeSalesChannel($sale->channel ?: 'pos') !== 'pos') {
            return;
        }

        if (in_array((string) $sale->status, ['held', 'draft', 'cancelled'], true)) {
            return;
        }

        if (! $this->saleHasSuccessfulKraResponse($sale)) {
            return;
        }

        $this->customerReturnService->approvePosEditVoid($sale, $user, $gate);
    }

    public function saleHasSuccessfulKraResponse(Sale $sale): bool
    {
        return KraResponse::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'success')
            ->exists();
    }
}
