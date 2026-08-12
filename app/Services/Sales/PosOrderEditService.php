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

    public function assertSaleEditable(
        Sale $sale,
        User $user,
        CapabilityGate $gate,
        ?string $requestDeviceId = null,
    ): void
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

        $workflowService = OrderWorkflowService::forGate($gate);
        $status = (string) $sale->status;
        $channel = $workflowService->normalizeSalesChannel($sale->channel ?: 'pos');
        $orderSource = strtolower((string) ($sale->order_source ?? ''));
        $isPosSale = $channel === 'pos' || $orderSource === 'pos';

        // External POS previous-order edit: only the till that printed the receipt.
        if ($isPosSale && $this->posOrderEditEnabled($gate)) {
            $this->assertSaleWrittenOnRequestDevice($sale, $requestDeviceId);
        }

        // LightStores parity: when “Allow editing completed POS orders” is on, cashiers can
        // pull back any non-expired POS receipt (held / unpaid / paid / completed / …),
        // adjust lines + stock, then re-checkout under the same order number.
        if ($isPosSale && $this->posOrderEditEnabled($gate)) {
            if (in_array($status, ['expired'], true)) {
                throw new InvalidArgumentException('Expired orders cannot be edited.');
            }

            return;
        }

        // Sales & Orders “Edit Order” follows Platform → Edit order stages (defaults: booked, pending).
        if (! $workflowService->isEditableLineStatus($status, $channel)) {
            if ($isPosSale) {
                throw new InvalidArgumentException(
                    'Editing completed POS orders is disabled. Enable “Allow editing completed POS orders” under Platform → Sales behaviour.',
                );
            }

            throw new InvalidArgumentException(
                "This order cannot be edited in its current status ({$status}).",
            );
        }
    }

    /**
     * Block previous-order edit when the sale is stamped to a different POS computer.
     * Unstamped legacy sales are not rejected here — the till filters browse/open by local outbox.
     */
    public function assertSaleWrittenOnRequestDevice(Sale $sale, ?string $requestDeviceId): void
    {
        $writtenOn = trim((string) (($sale->fulfillment_meta ?? [])['pos_device_id'] ?? ''));
        if ($writtenOn === '') {
            return;
        }

        $requestDevice = trim((string) ($requestDeviceId ?? ''));
        if ($requestDevice === '' || $requestDevice !== $writtenOn) {
            throw new InvalidArgumentException(
                'This Cash Sales # was written on another device. Previous-order edit is only available on the till that printed the receipt.',
            );
        }
    }

    public function canRestoreSaleToCart(
        Sale $sale,
        User $user,
        CapabilityGate $gate,
        ?string $requestDeviceId = null,
    ): bool
    {
        try {
            $this->assertSaleEditable($sale, $user, $gate, $requestDeviceId);

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
        if (! $this->saleNeedsFiscalVoidBeforeEdit($sale, $gate)) {
            return;
        }

        $this->customerReturnService->approvePosEditVoid($sale, $user, $gate);
    }

    /**
     * Whether restore/checkout should (still) issue a KRA credit note for this sale.
     */
    public function saleNeedsFiscalVoidBeforeEdit(Sale $sale, CapabilityGate $gate): bool
    {
        $sales = $gate->moduleSettings('sales');
        $allowed = (bool) ($sales['enable_pos_order_edit'] ?? false)
            || (bool) ($sales['append_same_day_customer_orders'] ?? false);
        if (! $allowed) {
            return false;
        }

        if (OrderWorkflowService::forGate($gate)->normalizeSalesChannel($sale->channel ?: 'pos') !== 'pos') {
            return false;
        }

        if (in_array((string) $sale->status, ['held', 'draft', 'cancelled'], true)) {
            return false;
        }

        // Already voided for a prior restore attempt — skip KRA/device round-trip.
        if (\App\Models\CustomerReturn::query()
            ->where('sale_id', $sale->id)
            ->where('return_kind', 'pos_edit')
            ->where('status', 'approved')
            ->exists()) {
            return false;
        }

        return $this->saleHasSuccessfulKraResponse($sale);
    }

    public function saleHasSuccessfulKraResponse(Sale $sale): bool
    {
        // Recent successes only — avoid loading every historical kra_responses row.
        $rows = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'response_payload']);

        return $rows->contains(function (KraResponse $kra): bool {
            $docType = strtolower(trim((string) (($kra->response_payload ?? [])['document_type'] ?? '')));

            return $docType !== 'credit_note';
        });
    }
}
