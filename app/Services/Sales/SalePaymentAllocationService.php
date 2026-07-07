<?php

namespace App\Services\Sales;

use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\TillFloatSession;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\CustomerPaymentJournalService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Erp\SalePaymentColumnMapper;
use App\Services\Fulfillment\TripAutoCloseService;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalePaymentAllocationService
{
    public function __construct(protected ErpContext $erp) {}

    /**
     * @param  array{payment_method_id: int, amount: float, reference_number?: ?string, float_session_id?: ?int, received_by?: ?int}  $payment
     */
    public function allocate(Sale $sale, array $payment, User $user): Sale
    {
        $amount = (float) $payment['amount'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        return DB::transaction(function () use ($sale, $payment, $amount, $user) {
            $priorPaid = (float) $sale->amount_paid;
            $collectsReceivable = (bool) $sale->is_credit_sale
                || $sale->customer_num
                || $priorPaid + 0.01 < (float) $sale->order_total;

            SalePayment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $payment['payment_method_id'],
                'amount' => $amount,
                'reference_number' => $payment['reference_number'] ?? null,
                'float_session_id' => $this->resolvePaymentFloatSessionId($payment, $user),
            ]);

            $newPaid = (float) $sale->amount_paid + $amount;
            $paymentStatus = $this->derivePaymentStatus((float) $sale->order_total, $newPaid);

            $gate = $this->erp->gateForUser($user);
            $workflow = OrderWorkflowService::forGate($gate);
            $salesSettings = $gate->moduleSettings('sales');
            $method = PaymentMethod::find($payment['payment_method_id']);
            $paymentMethodCode = $method?->method_code ?? 'CASH';

            $orderStatus = $workflow->resolveStatusAfterPayment(
                (string) $sale->channel,
                (string) $sale->status,
                $newPaid,
                (float) $sale->order_total,
                (bool) $sale->is_credit_sale,
                $paymentMethodCode,
                ! empty($salesSettings['allow_credit_pay_now']),
            );

            $updates = [
                'amount_paid' => $newPaid,
                'payment_status' => $paymentStatus,
            ];

            if ($sale->status !== 'cancelled' && $sale->status !== 'held') {
                $updates['status'] = $orderStatus;
                if ($workflow->isTerminalStatus($orderStatus, (string) $sale->channel)) {
                    $updates['completed_at'] = $sale->completed_at ?? now();
                }
            }

            $sale->update($updates);
            SalePaymentColumnMapper::applyToSale($sale->fresh(), $paymentMethodCode, $amount);

            if ($sale->customer_num) {
                $invoice = app(CustomerInvoiceService::class)->ensureForSale(
                    $sale->fresh(),
                    $user,
                    (float) $sale->order_total,
                    $newPaid,
                );
                if ($invoice) {
                    CustomerInvoicePayment::create([
                        'customer_invoice_id' => $invoice->id,
                        'customer_num' => $sale->customer_num,
                        'payment_method_id' => $payment['payment_method_id'],
                        'amount_paid' => $amount,
                        'date_paid' => now()->toDateString(),
                        'received_by' => $payment['received_by'] ?? $user->id,
                        'organization_id' => $sale->organization_id,
                        'reference_number' => $payment['reference_number'] ?? null,
                    ]);
                }
            }

            $sale = $sale->fresh();
            app(TripAutoCloseService::class)->tryAutoCloseTripsForSale($sale, $user);

            app(\App\Services\Audit\OperationalAuditService::class)->logSalePayment(
                $user,
                $sale,
                $amount,
                isset($payment['payment_method_id']) ? (int) $payment['payment_method_id'] : null,
            );

            if ($collectsReceivable) {
                $gate = $this->erp->gateForUser($user);
                app(CustomerPaymentJournalService::class)->postIfEnabled(
                    $sale,
                    $user,
                    $gate,
                    $amount,
                    (int) $payment['payment_method_id'],
                );
            }

            $organization = Organization::find($user->organization_id);
            if ($organization) {
                app(CustomerNotificationService::class)->notifyDebtorPayment($sale, $organization, $amount);
            }

            return $sale;
        });
    }

    protected function derivePaymentStatus(float $total, float $paid): string
    {
        if ($paid <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.01 >= $total) {
            return 'paid';
        }

        return 'partial';
    }

    /** @param  array<string, mixed>  $payment */
    protected function resolvePaymentFloatSessionId(array $payment, User $user): ?int
    {
        $sessionId = isset($payment['float_session_id']) ? (int) $payment['float_session_id'] : null;
        if (! $sessionId) {
            return null;
        }

        $session = TillFloatSession::find($sessionId);
        if (! $session || strtolower((string) $session->status) !== 'open') {
            throw new InvalidArgumentException('Payments can only be linked to an open till session.');
        }
        if ((int) $session->cashier_id !== (int) $user->id) {
            throw new InvalidArgumentException('Till session belongs to another cashier.');
        }

        return $sessionId;
    }
}
