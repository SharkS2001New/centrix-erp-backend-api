<?php

namespace App\Services\Sales;

use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use InvalidArgumentException;

class PosOrderEditService
{
    public function __construct(
        protected CustomerReturnService $customerReturnService,
    ) {}

    public function posOrderEditEnabled(CapabilityGate $gate): bool
    {
        return (bool) ($gate->moduleSettings('sales')['enable_pos_order_edit'] ?? false);
    }

    /** @return list<string> */
    public function editableStatusesForChannel(string $channel, CapabilityGate $gate): array
    {
        if (in_array($channel, ['backend', 'backoffice'], true)) {
            return ['held'];
        }

        if ($channel === 'pos' && ! $this->posOrderEditEnabled($gate)) {
            return ['held'];
        }

        $workflowService = OrderWorkflowService::forGate($gate);
        $workflow = $workflowService->forChannel($channel);
        $terminal = $workflowService->lastPipelineStatus($channel);
        $editable = array_values(array_filter(
            $workflow['statuses'] ?? [],
            fn (string $status) => $status !== $terminal && $status !== 'cancelled',
        ));

        return array_values(array_unique(array_merge(['held', 'draft'], $editable)));
    }

    public function assertSaleEditable(Sale $sale, User $user, CapabilityGate $gate): void
    {
        if ($sale->status === 'cancelled' || (int) ($sale->archived ?? 0) === 1) {
            throw new InvalidArgumentException('This order cannot be edited.');
        }

        if ((bool) (($sale->fulfillment_meta ?? [])['legacy_import'] ?? false)) {
            throw new InvalidArgumentException('Legacy materialized orders cannot be edited from POS.');
        }

        if ((int) $sale->cashier_id !== (int) $user->id && ! $user->is_admin) {
            throw new InvalidArgumentException('You can only edit your own orders.');
        }

        $channel = $sale->channel ?: 'pos';
        $editable = $this->editableStatusesForChannel($channel, $gate);

        if (! in_array((string) $sale->status, $editable, true)) {
            throw new InvalidArgumentException('This order cannot be edited in its current status.');
        }
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

        if (($sale->channel ?: 'pos') !== 'pos') {
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
