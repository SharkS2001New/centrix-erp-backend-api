<?php

namespace App\Services\Mpesa;

use App\Models\CustomerInvoice;
use App\Models\MpesaIncomingPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SalePaymentAllocationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MpesaPaymentApplicationService
{
    public function __construct(
        protected SalePaymentAllocationService $salePaymentAllocator,
    ) {}

    public function applyToSale(
        MpesaIncomingPayment $payment,
        Sale $sale,
        User $user,
        ?float $requestedAmount = null,
        string $matchMethod = 'manual',
        ?string $notes = null,
    ): MpesaIncomingPayment {
        if ($payment->status !== 'available') {
            throw new InvalidArgumentException('Payment not found or already used.');
        }
        if ((int) $payment->organization_id !== (int) $sale->organization_id) {
            throw new InvalidArgumentException('Payment and order belong to different organizations.');
        }

        $balanceDue = round((float) $sale->order_total - (float) $sale->amount_paid, 2);
        if ($balanceDue <= 0) {
            throw new InvalidArgumentException('This order has already been fully paid.');
        }

        $paymentAmount = (float) $payment->amount;
        $toApply = $requestedAmount !== null ? (float) $requestedAmount : $paymentAmount;
        $toApply = min($toApply, $paymentAmount, $balanceDue);
        if ($toApply < 1) {
            throw new InvalidArgumentException('Payment amount is too small to apply.');
        }

        $method = $this->resolveMpesaPaymentMethod((int) $sale->organization_id);

        return DB::transaction(function () use ($payment, $sale, $user, $toApply, $paymentAmount, $method, $matchMethod, $notes) {
            $this->salePaymentAllocator->allocate($sale, [
                'payment_method_id' => (int) $method->id,
                'amount' => $toApply,
                'reference_number' => $payment->transaction_id,
                'received_by' => $user->id,
            ], $user);

            $remainder = (int) round($paymentAmount - $toApply);
            if ($remainder >= 1) {
                MpesaIncomingPayment::query()->create([
                    'organization_id' => $payment->organization_id,
                    'transaction_id' => $payment->transaction_id.'-R'.$remainder.'-'.uniqid(),
                    'phone_number' => $payment->phone_number,
                    'amount' => $remainder,
                    'bill_ref_number' => $payment->bill_ref_number,
                    'payer_name' => $payment->payer_name,
                    'business_short_code' => $payment->business_short_code,
                    'parsed_order_num' => $payment->parsed_order_num,
                    'parsed_customer_num' => $payment->parsed_customer_num,
                    'source' => $payment->source,
                    'status' => 'available',
                    'reconciliation_status' => 'unmatched',
                    'stk_request_id' => $payment->stk_request_id,
                    'received_at' => $payment->received_at,
                ]);
            }

            $invoiceId = CustomerInvoice::query()
                ->where('sale_id', $sale->id)
                ->value('id');

            $payment->update([
                'status' => 'applied',
                'applied_sale_id' => $sale->id,
                'applied_invoice_id' => $invoiceId ? (int) $invoiceId : null,
                'applied_amount' => (int) round($toApply),
                'applied_at' => now(),
                'matched_at' => now(),
                'matched_by_user_id' => $user->id,
                'match_method' => $matchMethod,
                'match_confidence' => $matchMethod === 'manual' ? 'high' : $payment->match_confidence,
                'reconciliation_status' => 'matched',
                'reconciliation_notes' => $notes,
            ]);

            return $payment->fresh();
        });
    }

    protected function resolveMpesaPaymentMethod(int $organizationId): PaymentMethod
    {
        $method = PaymentMethod::query()
            ->where('organization_id', $organizationId)
            ->where('method_code', 'MPESA')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $method) {
            $method = PaymentMethod::query()
                ->where('organization_id', $organizationId)
                ->where('method_code', 'MPESA')
                ->orderBy('id')
                ->first();
        }

        if (! $method) {
            throw new InvalidArgumentException('M-Pesa payment method is not configured for this organization.');
        }

        return $method;
    }
}
