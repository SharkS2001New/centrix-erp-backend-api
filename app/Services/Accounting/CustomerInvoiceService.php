<?php

namespace App\Services\Accounting;

use App\Models\CustomerInvoice;
use App\Models\Sale;
use App\Models\User;

class CustomerInvoiceService
{
    public function ensureForSale(
        Sale $sale,
        User $user,
        ?float $invoiceTotal = null,
        ?float $amountPaid = null,
    ): ?CustomerInvoice {
        if (! $sale->customer_num) {
            return null;
        }

        $total = round((float) ($invoiceTotal ?? $sale->order_total), 2);
        if ($total <= 0.01) {
            return null;
        }

        $paid = round((float) ($amountPaid ?? $sale->amount_paid), 2);
        $paymentStatus = $this->paymentStatus($total, $paid);

        $existing = CustomerInvoice::query()
            ->where('sale_id', $sale->id)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $updates = [];
            if (round((float) $existing->invoice_total, 2) !== $total) {
                $updates['invoice_total'] = $total;
            }
            if (round((float) ($existing->total_vat ?? 0), 2) !== round((float) $sale->total_vat, 2)) {
                $updates['total_vat'] = $sale->total_vat;
            }
            if (round((float) $existing->amount_paid, 2) !== $paid) {
                $updates['amount_paid'] = $paid;
            }
            if ((int) $existing->payment_status !== $paymentStatus) {
                $updates['payment_status'] = $paymentStatus;
            }
            if ($updates !== []) {
                $existing->update($updates);
            }

            return $existing->fresh();
        }

        return CustomerInvoice::create([
            'invoice_number' => 'AR-'.$sale->order_num,
            'sale_id' => $sale->id,
            'customer_num' => $sale->customer_num,
            'branch_id' => $sale->branch_id,
            'organization_id' => $sale->organization_id,
            'created_by' => $user->id,
            'invoice_date' => now()->toDateString(),
            'total_vat' => $sale->total_vat,
            'invoice_total' => $total,
            'amount_paid' => $paid,
            'payment_status' => $paymentStatus,
        ]);
    }

    protected function paymentStatus(float $total, float $paid): int
    {
        if ($paid + 0.01 >= $total) {
            return 2;
        }
        if ($paid > 0.01) {
            return 1;
        }

        return 0;
    }
}
