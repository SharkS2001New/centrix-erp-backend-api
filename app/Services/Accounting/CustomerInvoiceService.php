<?php

namespace App\Services\Accounting;

use App\Models\Customer;
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
            'invoice_number' => $this->allocateInvoiceNumber($sale),
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

    public function voidForCancelledSale(Sale $sale, User $user): void
    {
        if (! $sale->customer_num) {
            return;
        }

        $invoices = CustomerInvoice::query()
            ->where('sale_id', $sale->id)
            ->whereNull('deleted_at')
            ->get();

        if ($invoices->isEmpty()) {
            return;
        }

        foreach ($invoices as $invoice) {
            $invoice->update([
                'invoice_number' => $this->voidedInvoiceNumber($invoice),
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);
        }

        $this->refreshCustomerBalance((int) $sale->organization_id, (int) $sale->customer_num);
    }

    protected function refreshCustomerBalance(int $organizationId, int $customerNum): void
    {
        $balance = CustomerInvoice::query()
            ->where('organization_id', $organizationId)
            ->where('customer_num', $customerNum)
            ->whereIn('payment_status', [0, 1])
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(invoice_total - amount_paid), 0) as balance')
            ->value('balance');

        Customer::query()
            ->where('organization_id', $organizationId)
            ->where('customer_num', $customerNum)
            ->update(['current_balance' => round((float) $balance, 2)]);
    }

    /**
     * Pick a unique AR invoice number for the sale. Reuses AR-{order_num} when possible.
     * Voided invoices keep their row but release the number (see voidedInvoiceNumber).
     */
    protected function allocateInvoiceNumber(Sale $sale): string
    {
        $number = 'AR-'.$sale->order_num;
        $orgId = (int) $sale->organization_id;

        $existing = CustomerInvoice::query()
            ->where('organization_id', $orgId)
            ->where('invoice_number', $number)
            ->first();

        if (! $existing) {
            return $number;
        }

        if ($existing->deleted_at !== null) {
            $existing->update(['invoice_number' => $this->voidedInvoiceNumber($existing)]);

            return $number;
        }

        if ((int) $existing->sale_id === (int) $sale->id) {
            return $number;
        }

        return 'AR-'.$sale->order_num.'-S'.$sale->id;
    }

    protected function voidedInvoiceNumber(CustomerInvoice $invoice): string
    {
        $base = (string) $invoice->invoice_number;
        if (str_contains($base, '-VOID-')) {
            return $base;
        }

        return $base.'-VOID-'.$invoice->id;
    }
}
