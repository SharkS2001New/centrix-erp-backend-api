<?php

namespace App\Services\Accounting;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $oldCustomerNum = (int) ($existing->customer_num ?? 0);
            $newCustomerNum = (int) $sale->customer_num;
            $updates = [];
            if ($oldCustomerNum !== $newCustomerNum && $newCustomerNum > 0) {
                $updates['customer_num'] = $newCustomerNum;
            }
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

            if (
                $oldCustomerNum !== $newCustomerNum
                && $newCustomerNum > 0
                && Schema::hasTable('customer_invoice_payments')
            ) {
                CustomerInvoicePayment::query()
                    ->where('customer_invoice_id', $existing->id)
                    ->update(['customer_num' => $newCustomerNum]);
            }

            $invoice = $existing->fresh();
            $this->refreshCustomerBalance((int) $sale->organization_id, $newCustomerNum);
            if ($oldCustomerNum > 0 && $oldCustomerNum !== $newCustomerNum) {
                $this->refreshCustomerBalance((int) $sale->organization_id, $oldCustomerNum);
            }

            return $invoice;
        }

        $invoice = CustomerInvoice::create([
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

        $this->refreshCustomerBalance((int) $sale->organization_id, (int) $sale->customer_num);

        return $invoice;
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

    /**
     * Customer returns shrink sale.order_total (and the observer may sync that into AR).
     * Restore the invoice to the original gross so statements can show Invoice + Credit note separately.
     */
    public function preserveOriginalTotalAfterReturn(Sale $sale): void
    {
        if (! $sale->customer_num) {
            return;
        }

        $invoice = CustomerInvoice::query()
            ->where('sale_id', $sale->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $invoice) {
            return;
        }

        $credits = $this->statementCreditTotalForSale((int) $sale->id);
        $gross = round((float) $sale->order_total + $credits, 2);
        if ($gross <= 0.01) {
            return;
        }

        $paid = round((float) ($sale->amount_paid ?? $invoice->amount_paid), 2);
        $effectiveDue = max(0, round($gross - $paid - $credits, 2));
        $paymentStatus = $effectiveDue <= 0.01 ? 2 : ($paid > 0.01 ? 1 : 0);
        $updates = [];
        if (round((float) $invoice->invoice_total, 2) !== $gross) {
            $updates['invoice_total'] = $gross;
        }
        if (round((float) $invoice->amount_paid, 2) !== $paid) {
            $updates['amount_paid'] = $paid;
        }
        if ((int) $invoice->payment_status !== $paymentStatus) {
            $updates['payment_status'] = $paymentStatus;
        }
        if ($updates !== []) {
            $invoice->update($updates);
        }

        $this->refreshCustomerBalance((int) $sale->organization_id, (int) $sale->customer_num);
    }

    public function refreshCustomerBalance(int $organizationId, int $customerNum): void
    {
        $invoices = CustomerInvoice::query()
            ->where('organization_id', $organizationId)
            ->where('customer_num', $customerNum)
            ->whereIn('payment_status', [0, 1])
            ->whereNull('deleted_at')
            ->get(['id', 'sale_id', 'invoice_total', 'amount_paid']);

        $saleIds = $invoices->pluck('sale_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $saleTotals = $saleIds->isEmpty()
            ? collect()
            : Sale::query()->whereIn('id', $saleIds)->pluck('order_total', 'id');
        $creditsBySale = $this->statementCreditsBySaleId($saleIds->all());

        $balance = 0.0;
        foreach ($invoices as $invoice) {
            $saleId = $invoice->sale_id ? (int) $invoice->sale_id : null;
            $credits = $saleId ? (float) ($creditsBySale[$saleId] ?? 0) : 0.0;
            $netSale = $saleId !== null
                ? (float) ($saleTotals[$saleId] ?? $invoice->invoice_total)
                : (float) $invoice->invoice_total;
            $gross = round(max((float) $invoice->invoice_total, $netSale + $credits), 2);
            $balance += max(0, round($gross - (float) $invoice->amount_paid - $credits, 2));
        }

        Customer::query()
            ->where('organization_id', $organizationId)
            ->where('customer_num', $customerNum)
            ->update(['current_balance' => round($balance, 2)]);
    }

    /** @param  list<int>  $saleIds */
    public function statementCreditsBySaleId(array $saleIds): array
    {
        if ($saleIds === [] || ! Schema::hasTable('credit_notes')) {
            return [];
        }

        $query = CreditNote::query()
            ->whereIn('sale_id', $saleIds)
            ->selectRaw('sale_id, COALESCE(SUM(total_amount), 0) as total')
            ->groupBy('sale_id');

        if (Schema::hasTable('customer_returns') && Schema::hasColumn('customer_returns', 'return_kind')) {
            $query->where(function ($q) {
                $q->whereNull('customer_return_id')
                    ->orWhereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('customer_returns')
                            ->whereColumn('customer_returns.id', 'credit_notes.customer_return_id')
                            ->where('customer_returns.return_kind', 'pos_edit');
                    });
            });
        }

        return $query->pluck('total', 'sale_id')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    public function statementCreditTotalForSale(int $saleId): float
    {
        return (float) ($this->statementCreditsBySaleId([$saleId])[$saleId] ?? 0);
    }

    public function statementDebitForInvoice(float $invoiceTotal, float $netSaleTotal, float $credits): float
    {
        return round(max($invoiceTotal, $netSaleTotal + $credits), 2);
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
