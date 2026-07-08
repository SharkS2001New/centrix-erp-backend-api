<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\CartLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaleCancellationService
{
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function cancelSale(Sale $sale, User $user, ?CapabilityGate $gate = null): Sale
    {
        $gate ??= $this->erp->gateForUser($user);
        $workflow = OrderWorkflowService::forGate($gate);
        $from = (string) $sale->status;

        if ($from === 'cancelled') {
            return $sale;
        }

        if (! $workflow->canTransition($from, 'cancelled', $sale->channel)) {
            throw new InvalidArgumentException(
                "Cannot cancel order from {$from}.",
            );
        }

        if (! $workflow->orderCancellationEnabled()) {
            throw new InvalidArgumentException('Order cancellation is disabled for this organization.');
        }

        DB::transaction(function () use ($sale, $user, $gate) {
            $this->restoreCancelledSaleStock($sale, $user);

            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'stock_balanced' => 0,
            ]);

            app(CustomerInvoiceService::class)->voidForCancelledSale($sale->fresh(), $user);

            app(ReferenceJournalReversalService::class)->reverseIfEnabled(
                'sale',
                (int) $sale->id,
                $user,
                $gate,
            );

            app(ActionRequestService::class)->cancelAllPendingForSale(
                $sale->fresh(),
                $user,
                'Order was cancelled.',
            );

            app(AuditLogger::class)->log(
                $user,
                'cancel',
                'sales',
                (int) $sale->id,
                ['status' => $from],
                ['status' => 'cancelled', 'cancelled_by' => $user->id],
            );
        });

        return $sale->fresh();
    }

    public function cancellationApprovalEnabled(CapabilityGate $gate): bool
    {
        $salesSettings = $gate->moduleSettings('sales');

        return ! empty($salesSettings['order_cancellation_approval_enabled']);
    }
}
