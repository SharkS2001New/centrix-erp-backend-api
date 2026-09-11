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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SalePaymentAllocationService
{
    public function __construct(protected ErpContext $erp) {}

    /**
     * @param  array{payment_method_id: int, amount: float, reference_number?: ?string, float_session_id?: ?int, received_by?: ?int}  $payment
     */
    public function allocate(Sale $sale, array $payment, User $user): Sale
    {
        return $this->allocateMany($sale, [$payment], $user);
    }

    /**
     * Record one or more tenders in a single transaction.
     *
     * Split methods (cash + M-Pesa) must succeed or fail together. Posting them
     * one request at a time left the first tender on the sale when the next 422'd,
     * so cashiers saw "Payment failed" on an order that was already partial/paid.
     *
     * Partial-vs-full is judged on the batch total, not each line — otherwise a
     * full settlement of 4,000 cash + 6,000 M-Pesa is rejected as a 4,000 partial.
     *
     * @param  list<array{payment_method_id: int, amount: float, reference_number?: ?string, float_session_id?: ?int, received_by?: ?int}>  $payments
     */
    public function allocateMany(Sale $sale, array $payments, User $user): Sale
    {
        $normalized = $this->normalizePaymentBatch($payments);
        $batchTotal = round(array_sum(array_column($normalized, 'amount')), 2);

        $sale = $sale->fresh() ?? $sale;
        $this->assertAmountWithinBalanceDue($sale, $batchTotal);
        $this->assertPartialPaymentAllowed($sale, $batchTotal, $user);

        return DB::transaction(function () use ($sale, $normalized, $batchTotal, $user) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $this->assertAmountWithinBalanceDue($sale, $batchTotal);
            $this->assertPartialPaymentAllowed($sale, $batchTotal, $user);

            foreach ($normalized as $payment) {
                $sale = $this->applyLockedPayment($sale, $payment, $user);
            }

            return $sale->fresh() ?? $sale;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return list<array{payment_method_id: int, amount: float, reference_number?: ?string, float_session_id?: ?int, received_by?: ?int}>
     */
    protected function normalizePaymentBatch(array $payments): array
    {
        $normalized = [];
        foreach ($payments as $payment) {
            $amount = round((float) ($payment['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount must be positive.'],
                ]);
            }
            $methodId = (int) ($payment['payment_method_id'] ?? 0);
            if ($methodId <= 0) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ['Select a payment method.'],
                ]);
            }
            $normalized[] = array_merge($payment, [
                'amount' => $amount,
                'payment_method_id' => $methodId,
            ]);
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'amount' => ['Enter a payment amount greater than zero.'],
            ]);
        }

        $methodIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['payment_method_id'],
            $normalized,
        )));
        $found = PaymentMethod::query()->whereIn('id', $methodIds)->pluck('id')->all();
        $found = array_map('intval', $found);
        foreach ($methodIds as $methodId) {
            if (! in_array($methodId, $found, true)) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ['Payment method is not set up.'],
                ]);
            }
        }

        return $normalized;
    }

    /**
     * @param  array{payment_method_id: int, amount: float, reference_number?: ?string, float_session_id?: ?int, received_by?: ?int}  $payment
     */
    protected function applyLockedPayment(Sale $sale, array $payment, User $user): Sale
    {
        $amount = round((float) $payment['amount'], 2);
        $this->assertAmountWithinBalanceDue($sale, $amount);

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
            // Backoffice collect installments only — not External POS checkout.
            ! empty($salesSettings['allow_credit_pay_now']),
        );

        $updates = [
            'amount_paid' => $newPaid,
            'payment_status' => $paymentStatus,
            // Keep primary method in sync for Orders/Sales Method column — cheque/bank
            // tenders do not write cash/mpesa buckets, so the list UI relies on this code.
            'payment_method_code' => $paymentMethodCode,
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
                    'branch_id' => $sale->branch_id ? (int) $sale->branch_id : null,
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

        return $sale->fresh() ?? $sale;
    }

    public function assertAmountWithinBalanceDue(Sale $sale, float $amount): void
    {
        $orderTotal = round((float) $sale->order_total, 2);
        $alreadyPaid = round((float) $sale->amount_paid, 2);
        $balanceDue = round(max(0, $orderTotal - $alreadyPaid), 2);

        if ($balanceDue <= 0.01) {
            throw ValidationException::withMessages([
                'amount' => ['This order has already been fully paid.'],
            ]);
        }

        if ($amount - $balanceDue > 0.01) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'Payment of %.2f exceeds the amount due of %.2f. Enter the correct amount to continue.',
                    $amount,
                    $balanceDue,
                )],
            ]);
        }
    }

    /**
     * Backoffice Collect payment installments — gated by allow_credit_pay_now only.
     * Does not affect External POS / Create order checkout (those never call this service).
     */
    public function assertPartialPaymentAllowed(Sale $sale, float $amount, User $user): void
    {
        $orderTotal = round((float) $sale->order_total, 2);
        $alreadyPaid = round((float) $sale->amount_paid, 2);
        $balanceDue = round(max(0, $orderTotal - $alreadyPaid), 2);

        if ($amount + 0.01 >= $balanceDue) {
            return;
        }

        $salesSettings = $this->erp->gateForUser($user)->moduleSettings('sales');
        if (! empty($salesSettings['allow_credit_pay_now'])) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => ['Enter the full amount due, or enable “Allow collecting small payments” in sales settings (backoffice Collect payment).'],
        ]);
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

        // When the cashier has an open till session, debtor collections must land on
        // that session so X / Z / End of Day show "Invoice sales (paid debtors)".
        if (! $sessionId) {
            $open = TillFloatSession::query()
                ->where('cashier_id', $user->id)
                ->whereRaw('LOWER(status) = ?', ['open'])
                ->orderByDesc('id')
                ->first();

            return $open ? (int) $open->id : null;
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
