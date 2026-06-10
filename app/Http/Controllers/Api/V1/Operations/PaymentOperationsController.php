<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentOperationsController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    public function paySale(Request $request, int $saleId)
    {
        $sale = Sale::findOrFail($saleId);
        $data = $request->validate([
            'payment_method_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string',
        ]);
        $data['received_by'] = $request->user()->id;

        return response()->json($this->allocatePaymentToSale($sale, $data, $request->user()));
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

    protected function allocatePaymentToSale(Sale $sale, array $payment, $user): Sale
    {
        $amount = (float) $payment['amount'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        return DB::transaction(function () use ($sale, $payment, $amount, $user) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $payment['payment_method_id'],
                'amount' => $amount,
                'reference_number' => $payment['reference_number'] ?? null,
            ]);

            $newPaid = (float) $sale->amount_paid + $amount;
            $paymentStatus = $this->derivePaymentStatus((float) $sale->order_total, $newPaid);

            $gate = $this->erp->gateForUser($user);
            $workflow = OrderWorkflowService::forGate($gate);
            $salesSettings = $gate->moduleSettings('sales');
            $method = PaymentMethod::find($payment['payment_method_id']);
            $paymentMethodCode = $method?->method_code ?? 'CASH';

            $orderStatus = $workflow->resolveCheckoutStatus(
                $sale->channel,
                (bool) $sale->is_credit_sale,
                $newPaid,
                (float) $sale->order_total,
                $paymentMethodCode,
                ! empty($salesSettings['allow_credit_pay_now']),
            );

            $updates = [
                'amount_paid' => $newPaid,
                'payment_status' => $paymentStatus,
                'cash' => $sale->cash + (int) $amount,
            ];

            if ($sale->status !== 'cancelled' && $sale->status !== 'held') {
                $updates['status'] = $orderStatus;
                if ($orderStatus === 'completed') {
                    $updates['completed_at'] = $sale->completed_at ?? now();
                }
            }

            $sale->update($updates);

            if ($sale->customer_num) {
                $invoice = CustomerInvoice::where('sale_id', $sale->id)->first();
                if ($invoice) {
                    CustomerInvoicePayment::create([
                        'customer_invoice_id' => $invoice->id,
                        'customer_num' => $sale->customer_num,
                        'payment_method_id' => $payment['payment_method_id'],
                        'amount_paid' => $amount,
                        'date_paid' => now()->toDateString(),
                        'received_by' => $payment['received_by'],
                        'organization_id' => $sale->organization_id,
                        'reference_number' => $payment['reference_number'] ?? null,
                    ]);
                }
            }

            return $sale->fresh();
        });
    }
}
