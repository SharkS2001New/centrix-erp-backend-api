<?php

namespace App\Services\Audit;

use App\Models\Sale;
use App\Models\User;

/** Structured audit entries for operational events (checkout, payments, stock). */
class OperationalAuditService
{
    public function __construct(protected AuditLogger $audit) {}

    public function logSaleCheckout(User $user, Sale $sale): void
    {
        $this->audit->log(
            $user,
            'checkout',
            'sales',
            (int) $sale->id,
            null,
            [
                'order_num' => $sale->order_num,
                'order_total' => (float) $sale->order_total,
                'status' => (string) $sale->status,
                'channel' => (string) $sale->channel,
                'payment_status' => (string) $sale->payment_status,
            ],
        );
    }

    public function logSalePayment(User $user, Sale $sale, float $amount, ?int $paymentMethodId = null): void
    {
        $this->audit->log(
            $user,
            'payment',
            'sales',
            (int) $sale->id,
            null,
            [
                'amount' => round($amount, 2),
                'payment_method_id' => $paymentMethodId,
                'amount_paid' => (float) $sale->amount_paid,
                'payment_status' => (string) $sale->payment_status,
                'order_num' => $sale->order_num,
            ],
        );
    }

    /** @param  array<string, mixed>  $details */
    public function logTillExpense(User $user, int $expenseId, array $details): void
    {
        $this->audit->log(
            $user,
            'till_expense',
            'expenses',
            $expenseId,
            null,
            $details,
        );
    }

    /** @param  array<string, mixed>  $details */
    public function logStockMovement(User $user, string $action, array $details): void
    {
        $recordId = (string) ($details['product_code'] ?? $details['reference_id'] ?? '0');

        $this->audit->log(
            $user,
            $action,
            'stock_movements',
            $recordId,
            null,
            $details,
        );
    }
}
