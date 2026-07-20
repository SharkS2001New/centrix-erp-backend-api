<?php

namespace App\Services\Accounting;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerReceivablePaymentService
{
    public function __construct(protected ErpContext $erp) {}

    /**
     * Apply a payment against a customer's open AR invoices (oldest first),
     * or against a single invoice when customer_invoice_id is provided.
     *
     * @param  array{
     *   amount_paid: float|int|string,
     *   payment_method_id: int,
     *   customer_invoice_id?: int|null,
     *   reference_number?: ?string,
     *   cheque_number?: ?string,
     *   date_paid?: ?string,
     *   notes?: ?string
     * }  $data
     * @return array{payments: list<CustomerInvoicePayment>, amount_applied: float, customer: Customer}
     */
    public function receive(Customer $customer, User $user, array $data): array
    {
        $amount = round((float) $data['amount_paid'], 2);
        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'amount_paid' => ['Payment amount must be at least 0.01.'],
            ]);
        }

        $organizationId = (int) $customer->organization_id;
        $customerNum = (int) $customer->customer_num;
        $paymentMethodId = (int) $data['payment_method_id'];
        $datePaid = $data['date_paid'] ?? now()->toDateString();
        $reference = $data['reference_number'] ?? null;
        $cheque = $data['cheque_number'] ?? null;
        $notes = $data['notes'] ?? null;
        $invoiceId = isset($data['customer_invoice_id']) ? (int) $data['customer_invoice_id'] : null;

        return DB::transaction(function () use (
            $customer,
            $user,
            $amount,
            $organizationId,
            $customerNum,
            $paymentMethodId,
            $datePaid,
            $reference,
            $cheque,
            $notes,
            $invoiceId,
        ) {
            $invoices = $this->lockOpenInvoices($organizationId, $customerNum, $invoiceId);
            if ($invoices->isEmpty()) {
                throw ValidationException::withMessages([
                    'amount_paid' => ['This customer has no unpaid invoices to collect against.'],
                ]);
            }

            $outstanding = round((float) $invoices->sum(fn (CustomerInvoice $inv) => $this->balanceDue($inv)), 2);
            if ($amount - $outstanding > 0.01) {
                throw ValidationException::withMessages([
                    'amount_paid' => [sprintf(
                        'Payment cannot exceed the outstanding balance of %.2f.',
                        $outstanding,
                    )],
                ]);
            }

            $remaining = $amount;
            $created = [];
            $organization = Organization::find($organizationId);
            $gate = $this->erp->gateForUser($user);

            foreach ($invoices as $invoice) {
                if ($remaining < 0.01) {
                    break;
                }

                $balance = $this->balanceDue($invoice);
                if ($balance < 0.01) {
                    continue;
                }

                $apply = round(min($remaining, $balance), 2);
                if ($apply < 0.01) {
                    continue;
                }

                $payment = CustomerInvoicePayment::create([
                    'customer_invoice_id' => $invoice->id,
                    'customer_num' => $customerNum,
                    'payment_method_id' => $paymentMethodId,
                    'amount_paid' => $apply,
                    'amount_due_snapshot' => $balance,
                    'reference_number' => $reference,
                    'cheque_number' => $cheque,
                    'date_paid' => $datePaid,
                    'received_by' => $user->id,
                    'organization_id' => $organizationId,
                    'branch_id' => $invoice->branch_id ? (int) $invoice->branch_id : ($user->branch_id ? (int) $user->branch_id : null),
                    'notes' => $notes,
                ]);

                $invoice = app(CustomerInvoiceService::class)->finalizeRecordedPayment($payment, $user);
                $payment->setRelation('customerInvoice', $invoice);

                $sale = $invoice->loadMissing('sale')?->sale;
                if ($sale) {
                    app(CustomerPaymentJournalService::class)->postIfEnabled(
                        $sale,
                        $user,
                        $gate,
                        (float) $payment->amount_paid,
                        $paymentMethodId,
                    );
                }

                if ($organization) {
                    app(CustomerNotificationService::class)->notifyInvoicePayment($payment, $organization);
                }

                $created[] = $payment;
                $remaining = round($remaining - $apply, 2);
            }

            if ($created === []) {
                throw ValidationException::withMessages([
                    'amount_paid' => ['No payment could be applied to open invoices.'],
                ]);
            }

            return [
                'payments' => $created,
                'amount_applied' => round($amount - max(0, $remaining), 2),
                'customer' => $customer->fresh(),
            ];
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, CustomerInvoice>
     */
    protected function lockOpenInvoices(int $organizationId, int $customerNum, ?int $invoiceId)
    {
        $query = CustomerInvoice::query()
            ->where('organization_id', $organizationId)
            ->where('customer_num', $customerNum)
            ->whereIn('payment_status', [0, 1])
            ->whereNull('deleted_at')
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->lockForUpdate();

        if ($invoiceId) {
            $query->where('id', $invoiceId);
        }

        return $query->get();
    }

    protected function balanceDue(CustomerInvoice $invoice): float
    {
        return app(CustomerInvoiceService::class)->balanceDueFromPayments($invoice);
    }
}
