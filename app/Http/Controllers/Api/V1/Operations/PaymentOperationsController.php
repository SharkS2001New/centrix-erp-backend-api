<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentOperationsController extends Controller
{
    public function paySale(Request $request, int $saleId)
    {
        $sale = Sale::findOrFail($saleId);
        $data = $request->validate([
            'payment_method_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string',
        ]);
        $data['received_by'] = $request->user()->id;

        return response()->json($this->allocatePaymentToSale($sale, $data));
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

    protected function allocatePaymentToSale(Sale $sale, array $payment): Sale
    {
        $amount = (float) $payment['amount'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        return DB::transaction(function () use ($sale, $payment, $amount) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $payment['payment_method_id'],
                'amount' => $amount,
                'reference_number' => $payment['reference_number'] ?? null,
            ]);

            $newPaid = (float) $sale->amount_paid + $amount;
            $sale->update([
                'amount_paid' => $newPaid,
                'payment_status' => $this->derivePaymentStatus((float) $sale->order_total, $newPaid),
                'cash' => $sale->cash + (int) $amount,
            ]);

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
