<?php

namespace App\Services\Accounting;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\SalePaymentStatus;
use App\Services\Accounting\CustomerPaymentJournalService;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\TripAutoCloseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
            // Net total wiped (full return) — never leave a live AR row as "Paid".
            $this->voidForCancelledSale($sale, $user);

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
            $hasPayments = CustomerInvoicePayment::query()
                ->where('customer_invoice_id', $existing->id)
                ->exists();
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
            if (! $hasPayments) {
                if (round((float) $existing->amount_paid, 2) !== $paid) {
                    $updates['amount_paid'] = $paid;
                }
                if ((int) $existing->payment_status !== $paymentStatus) {
                    $updates['payment_status'] = $paymentStatus;
                }
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
            if ($this->invoiceHasCashLedger($invoice)) {
                $invoice = $this->syncPaidTotalsFromPayments($invoice);
            }
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

        if ($this->invoiceHasCashLedger($invoice)) {
            $invoice = $this->syncPaidTotalsFromPayments($invoice);
        }
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

    public function syncPaidTotalsFromPayments(CustomerInvoice $invoice): CustomerInvoice
    {
        $paid = round(max(
            $this->paidTotalFromPayments($invoice),
            $invoice->sale_id ? $this->saleTenderTotal((int) $invoice->sale_id) : 0.0,
        ), 2);
        $invoiceTotal = round((float) $invoice->invoice_total, 2);
        $credits = $this->creditTotalForInvoice($invoice);

        $invoice->update([
            'amount_paid' => $paid,
            'payment_status' => $this->paymentStatus($invoiceTotal, $paid + $credits),
        ]);

        return $invoice->fresh();
    }

    public function paidTotalFromPayments(CustomerInvoice $invoice): float
    {
        return round((float) CustomerInvoicePayment::query()
            ->where('customer_invoice_id', $invoice->id)
            ->sum('amount_paid'), 2);
    }

    /**
     * Cash on an AR invoice: max(invoice payment rows, sale_payments tenders).
     * Mixed checkout writes Cash + M-Pesa on the sale; a later M-Pesa-only invoice
     * row must not hide the cash tender and reopen a 44,800-style balance.
     */
    public function cashCollectedForInvoice(CustomerInvoice $invoice): float
    {
        $cip = $this->paidTotalFromPayments($invoice);
        $tenders = $invoice->sale_id
            ? $this->saleTenderTotal((int) $invoice->sale_id)
            : 0.0;
        $hasRows = $cip > 0.01 || $tenders > 0.01
            || CustomerInvoicePayment::query()
                ->where('customer_invoice_id', $invoice->id)
                ->exists();

        if ($hasRows) {
            return round(max($cip, $tenders), 2);
        }

        return round((float) ($invoice->amount_paid ?? 0), 2);
    }

    /**
     * True when invoice payment rows or sale_payments exist (even if amounts are 0).
     */
    protected function invoiceHasCashLedger(CustomerInvoice $invoice): bool
    {
        if (CustomerInvoicePayment::query()->where('customer_invoice_id', $invoice->id)->exists()) {
            return true;
        }

        return (int) ($invoice->sale_id ?? 0) > 0
            && Schema::hasTable('sale_payments')
            && SalePayment::query()->where('sale_id', $invoice->sale_id)->exists();
    }

    public function saleTenderTotal(int $saleId): float
    {
        if ($saleId <= 0 || ! Schema::hasTable('sale_payments')) {
            return 0.0;
        }

        return round((float) SalePayment::query()->where('sale_id', $saleId)->sum('amount'), 2);
    }

    public static function paidFromPaymentsSql(string $invoiceAlias = 'ci'): string
    {
        return "(SELECT COALESCE(SUM(cip.amount_paid), 0) FROM customer_invoice_payments cip WHERE cip.customer_invoice_id = {$invoiceAlias}.id)";
    }

    public static function saleTendersSql(string $invoiceAlias = 'ci'): string
    {
        return "(SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.sale_id = {$invoiceAlias}.sale_id)";
    }

    /**
     * Cash collected for list/report SQL — same max(invoice rows, sale tenders) as PHP.
     */
    public static function cashCollectedSql(string $invoiceAlias = 'ci'): string
    {
        $cip = self::paidFromPaymentsSql($invoiceAlias);
        $tenders = self::saleTendersSql($invoiceAlias);
        $cipOrColumn = 'CASE WHEN EXISTS ('
            .'SELECT 1 FROM customer_invoice_payments cip '
            ."WHERE cip.customer_invoice_id = {$invoiceAlias}.id"
            .") THEN {$cip} ELSE {$invoiceAlias}.amount_paid END";

        return "GREATEST({$cipOrColumn}, {$tenders})";
    }

    /**
     * Approved return credit notes for the invoice's sale (excludes POS-edit soft returns).
     */
    public static function creditsForInvoiceSaleSql(string $invoiceAlias = 'ci'): string
    {
        if (! Schema::hasTable('credit_notes')) {
            return '0';
        }

        $excludePosEdit = '';
        if (Schema::hasTable('customer_returns') && Schema::hasColumn('customer_returns', 'return_kind')) {
            $excludePosEdit = ' AND (cn.customer_return_id IS NULL OR NOT EXISTS ('
                .'SELECT 1 FROM customer_returns cr '
                .'WHERE cr.id = cn.customer_return_id AND cr.return_kind = \'pos_edit\''
                .'))';
        }

        return '(SELECT COALESCE(SUM(cn.total_amount), 0) FROM credit_notes cn '
            ."WHERE cn.sale_id = {$invoiceAlias}.sale_id{$excludePosEdit})";
    }

    public static function balanceDueFromPaymentsSql(string $invoiceAlias = 'ci'): string
    {
        $paid = self::cashCollectedSql($invoiceAlias);
        $credits = self::creditsForInvoiceSaleSql($invoiceAlias);

        return "GREATEST(0, ROUND({$invoiceAlias}.invoice_total - {$paid} - {$credits}, 2))";
    }

    public function creditTotalForInvoice(CustomerInvoice $invoice): float
    {
        if (! $invoice->sale_id) {
            return 0.0;
        }

        return $this->statementCreditTotalForSale((int) $invoice->sale_id);
    }

    public function balanceDueFromPayments(CustomerInvoice $invoice): float
    {
        $paid = $this->cashCollectedForInvoice($invoice);
        $credits = $this->creditTotalForInvoice($invoice);

        return round(max(0, (float) $invoice->invoice_total - $paid - $credits), 2);
    }

    protected function cashCollectedFromPreloaded(CustomerInvoice $invoice): float
    {
        $cip = isset($invoice->paid_from_payments_sum)
            ? round((float) $invoice->paid_from_payments_sum, 2)
            : $this->paidTotalFromPayments($invoice);
        $tenders = isset($invoice->sale_tenders_sum)
            ? round((float) $invoice->sale_tenders_sum, 2)
            : ($invoice->sale_id ? $this->saleTenderTotal((int) $invoice->sale_id) : 0.0);

        if ($cip > 0.01 || $tenders > 0.01) {
            return round(max($cip, $tenders), 2);
        }

        if (
            $invoice->id
            && CustomerInvoicePayment::query()->where('customer_invoice_id', $invoice->id)->exists()
        ) {
            return 0.0;
        }

        return round((float) ($invoice->amount_paid ?? 0), 2);
    }

    /**
     * Copy POS tenders that never became invoice payment rows, then refresh paid/status.
     */
    public function settleSaleTendersOntoInvoice(CustomerInvoice $invoice, Sale $sale, User $user): CustomerInvoice
    {
        $this->mirrorUnrecordedSaleTenders($invoice, $sale, $user);

        return $this->syncPaidTotalsFromPayments($invoice->fresh());
    }

    /**
     * Checkout writes sale_payments (Cash + M-Pesa) without invoice payment rows.
     * Copy any shortfall onto the invoice so Accounting matches the order.
     */
    public function mirrorUnrecordedSaleTenders(CustomerInvoice $invoice, Sale $sale, User $user): void
    {
        if (! $sale->id || ! Schema::hasTable('sale_payments') || ! Schema::hasTable('customer_invoice_payments')) {
            return;
        }

        $tenderSum = $this->saleTenderTotal((int) $sale->id);
        $cipSum = $this->paidTotalFromPayments($invoice);
        $gap = round($tenderSum - $cipSum, 2);
        if ($gap <= 0.01) {
            return;
        }

        $methodId = SalePayment::query()
            ->where('sale_id', $sale->id)
            ->orderByDesc('id')
            ->value('payment_method_id');
        if (! $methodId) {
            $methodId = PaymentMethod::query()->where('method_code', 'CASH')->value('id');
        }
        if (! $methodId) {
            return;
        }

        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $sale->customer_num,
            'payment_method_id' => $methodId,
            'amount_paid' => $gap,
            'date_paid' => now()->toDateString(),
            'received_by' => $user->id,
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id ? (int) $sale->branch_id : null,
            'notes' => 'Order tenders not yet on the invoice',
        ]);
    }

    /**
     * Same paid / balance / payment bucket Accounting uses, keyed by sale id.
     *
     * @param  list<int>  $saleIds
     * @return array<int, array{amount_paid: float, return_credit_total: float, balance_due: float, payment_status: string}>
     */
    public function settlementsBySaleId(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_map('intval', array_filter($saleIds))));
        if ($saleIds === []) {
            return [];
        }

        $sales = Sale::query()
            ->whereIn('id', $saleIds)
            ->get(['id', 'order_total', 'amount_paid', 'status'])
            ->keyBy('id');
        $invoices = CustomerInvoice::query()
            ->whereIn('sale_id', $saleIds)
            ->whereNull('deleted_at')
            ->withSum('payments as paid_from_payments_sum', 'amount_paid')
            ->get()
            ->keyBy('sale_id');
        $tendersBySale = Schema::hasTable('sale_payments')
            ? SalePayment::query()
                ->whereIn('sale_id', $saleIds)
                ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as tender_total')
                ->groupBy('sale_id')
                ->pluck('tender_total', 'sale_id')
            : collect();
        $creditsBySale = $this->statementCreditsBySaleId($saleIds);

        $out = [];
        foreach ($saleIds as $saleId) {
            $sale = $sales->get($saleId);
            if (! $sale) {
                continue;
            }
            $invoice = $invoices->get($saleId);
            $tenders = round((float) ($tendersBySale[$saleId] ?? 0), 2);
            if ($invoice) {
                $invoice->setAttribute('sale_tenders_sum', $tenders);
                $balances = $this->presentInvoiceBalances($invoice, $creditsBySale);
                $out[$saleId] = [
                    'amount_paid' => $balances['amount_paid'],
                    'return_credit_total' => $balances['return_credit_total'],
                    'balance_due' => $balances['balance_due'],
                    'payment_status' => SalePaymentStatus::resolve(
                        (string) ($sale->status ?? ''),
                        (float) ($invoice->invoice_total ?? $sale->order_total),
                        (float) $balances['amount_paid'] + (float) $balances['return_credit_total'],
                    ),
                ];
                continue;
            }

            $credits = round((float) ($creditsBySale[$saleId] ?? 0), 2);
            $cash = $tenders > 0.01
                ? round(max($tenders, (float) ($sale->amount_paid ?? 0)), 2)
                : round((float) ($sale->amount_paid ?? 0), 2);
            $total = round((float) ($sale->order_total ?? 0), 2);
            $out[$saleId] = [
                'amount_paid' => $cash,
                'return_credit_total' => $credits,
                'balance_due' => round(max(0, $total - $cash - $credits), 2),
                'payment_status' => SalePaymentStatus::resolve(
                    (string) ($sale->status ?? ''),
                    $total,
                    $cash + $credits,
                ),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, float>|null  $creditsBySale  Optional preloaded map sale_id => credit total
     * @return array{amount_paid: float, return_credit_total: float, balance_due: float, payment_status: int}
     */
    public function presentInvoiceBalances(CustomerInvoice $invoice, ?array $creditsBySale = null): array
    {
        $amountPaid = isset($invoice->paid_from_payments_sum) || isset($invoice->sale_tenders_sum)
            ? $this->cashCollectedFromPreloaded($invoice)
            : $this->cashCollectedForInvoice($invoice);

        $credits = 0.0;
        if ($invoice->sale_id) {
            $saleId = (int) $invoice->sale_id;
            $credits = $creditsBySale !== null
                ? round((float) ($creditsBySale[$saleId] ?? 0), 2)
                : $this->statementCreditTotalForSale($saleId);
        }

        $invoiceTotal = round((float) $invoice->invoice_total, 2);
        $balanceDue = round(max(0, $invoiceTotal - $amountPaid - $credits), 2);
        $paymentStatus = $this->paymentStatus($invoiceTotal, $amountPaid + $credits);

        if (
            (int) $invoice->payment_status !== $paymentStatus
            || abs(round((float) $invoice->amount_paid, 2) - $amountPaid) > 0.001
        ) {
            CustomerInvoice::query()->where('id', $invoice->id)->update([
                'amount_paid' => $amountPaid,
                'payment_status' => $paymentStatus,
            ]);
            $invoice->amount_paid = $amountPaid;
            $invoice->payment_status = $paymentStatus;
        }

        return [
            'amount_paid' => $amountPaid,
            'return_credit_total' => $credits,
            'balance_due' => $balanceDue,
            'payment_status' => $paymentStatus,
        ];
    }

    public function finalizeRecordedPayment(CustomerInvoicePayment $payment, User $user): CustomerInvoice
    {
        $payment->loadMissing('customerInvoice.sale');
        $invoice = $payment->customerInvoice;
        if (! $invoice) {
            throw ValidationException::withMessages([
                'payment' => ['Invoice for this payment was not found.'],
            ]);
        }

        $invoice = $this->syncPaidTotalsFromPayments($invoice);

        $sale = $invoice->sale;
        if ($sale) {
            app(TripAutoCloseService::class)->syncSaleFromInvoicePaidTotal(
                $sale,
                $user,
                (float) $invoice->amount_paid,
                (int) $payment->payment_method_id,
            );
        }

        $this->refreshCustomerBalance((int) $invoice->organization_id, (int) $invoice->customer_num);

        return $invoice;
    }

    public function voidInvoicePayment(CustomerInvoicePayment $payment, User $user): void
    {
        DB::transaction(function () use ($payment, $user) {
            $payment->loadMissing('customerInvoice.sale');
            $invoice = $payment->customerInvoice;
            if (! $invoice) {
                throw ValidationException::withMessages([
                    'payment' => ['Invoice for this payment was not found.'],
                ]);
            }

            $amount = round((float) $payment->amount_paid, 2);
            $paymentMethodId = (int) $payment->payment_method_id;
            $organizationId = (int) $payment->organization_id;
            $customerNum = (int) $payment->customer_num;
            $sale = $invoice->sale;

            $payment->delete();

            $invoice = $this->syncPaidTotalsFromPayments($invoice);

            if ($sale) {
                $gate = app(ErpContext::class)->gateForUser($user);
                app(TripAutoCloseService::class)->syncSaleFromInvoicePaidTotal(
                    $sale,
                    $user,
                    (float) $invoice->amount_paid,
                    $paymentMethodId,
                );
                app(CustomerPaymentJournalService::class)->reverseIfEnabled(
                    $sale,
                    $user,
                    $gate,
                    $amount,
                    $paymentMethodId,
                );
            }

            $this->refreshCustomerBalance($organizationId, $customerNum);
        });
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

    /** Reinstate the voided AR invoice when a cancelled sale is restored. */
    public function restoreForUncancelledSale(Sale $sale, User $user): ?CustomerInvoice
    {
        if (! $sale->customer_num) {
            return null;
        }

        $total = round((float) $sale->order_total, 2);
        if ($total <= 0.01) {
            return null;
        }

        $paid = round((float) $sale->amount_paid, 2);
        $paymentStatus = $this->paymentStatus($total, $paid);

        // If a live invoice already exists (e.g. ensureForSale ran after cancel), keep it.
        // Restoring a voided row onto AR-{order_num} would hit uq_org_customer_invoice_number.
        $active = CustomerInvoice::query()
            ->where('sale_id', $sale->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        if ($active) {
            return $this->ensureForSale($sale, $user, $total, $paid);
        }

        $voidedRows = CustomerInvoice::query()
            ->where('sale_id', $sale->id)
            ->whereNotNull('deleted_at')
            ->orderByDesc('id')
            ->get();
        $voided = $voidedRows->first(
            fn (CustomerInvoice $invoice) => str_ends_with((string) $invoice->invoice_number, '-VOID-'.$invoice->id),
        ) ?? $voidedRows->first();

        if ($voided) {
            $voided->update([
                'invoice_number' => $this->allocateInvoiceNumber($sale, (int) $voided->id),
                'deleted_at' => null,
                'deleted_by' => null,
                'invoice_total' => $total,
                'total_vat' => $sale->total_vat,
                'amount_paid' => $paid,
                'payment_status' => $paymentStatus,
                'customer_num' => $sale->customer_num,
                'branch_id' => $sale->branch_id,
            ]);

            $invoice = $voided->fresh();
            if (CustomerInvoicePayment::query()->where('customer_invoice_id', $invoice->id)->exists()) {
                $invoice = $this->syncPaidTotalsFromPayments($invoice);
            }

            $this->refreshCustomerBalance((int) $sale->organization_id, (int) $sale->customer_num);

            return $invoice;
        }

        return $this->ensureForSale($sale, $user, $total, $paid);
    }

    /**
     * After a customer return: full return (sale cancelled / net ~0) voids the AR
     * invoice so it disappears from Customer invoices. Partial return keeps the
     * gross invoice total and applies credit notes to the open balance.
     */
    public function reconcileAfterCustomerReturn(Sale $sale, User $user): void
    {
        $sale->refresh();
        $netTotal = round((float) $sale->order_total, 2);
        $cancelled = in_array((string) $sale->status, ['cancelled'], true);

        if ($cancelled || $netTotal <= 0.01) {
            $this->voidForCancelledSale($sale, $user);

            return;
        }

        $this->preserveOriginalTotalAfterReturn($sale);
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

        $sale->refresh();
        // Full returns must void — never leave a "Paid" invoice with no cash receipt.
        if (
            in_array((string) $sale->status, ['cancelled'], true)
            || round((float) $sale->order_total, 2) <= 0.01
        ) {
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
        // Due tracks remaining sale net (gross − credits − cash) = order_total − paid.
        $effectiveDue = max(0, round($gross - $paid - $credits, 2));
        $paymentStatus = $effectiveDue <= 0.01 ? 2 : ($paid > 0.01 ? 1 : 0);
        $updates = [];
        if (round((float) $invoice->invoice_total, 2) !== $gross) {
            $updates['invoice_total'] = $gross;
        }
        if ($updates !== []) {
            $invoice->update($updates);
        }

        if (CustomerInvoicePayment::query()->where('customer_invoice_id', $invoice->id)->exists()) {
            $this->syncPaidTotalsFromPayments($invoice->fresh());
        } elseif (round((float) $invoice->amount_paid, 2) !== $paid) {
            $invoice->update([
                'amount_paid' => $paid,
                'payment_status' => $paymentStatus,
            ]);
        } elseif ((int) $invoice->payment_status !== $paymentStatus) {
            $invoice->update(['payment_status' => $paymentStatus]);
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
            $cash = $this->cashCollectedForInvoice($invoice);
            $balance += max(0, round($gross - $cash - $credits, 2));
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
     *
     * @param  int|null  $excludeInvoiceId  Row being restored/updated — ignore it as a blocker.
     */
    protected function allocateInvoiceNumber(Sale $sale, ?int $excludeInvoiceId = null): string
    {
        $number = 'AR-'.$sale->order_num;
        $orgId = (int) $sale->organization_id;

        $existing = CustomerInvoice::query()
            ->where('organization_id', $orgId)
            ->where('invoice_number', $number)
            ->when(
                $excludeInvoiceId !== null && $excludeInvoiceId > 0,
                fn ($q) => $q->where('id', '!=', $excludeInvoiceId),
            )
            ->first();

        if (! $existing) {
            return $number;
        }

        if ($existing->deleted_at !== null) {
            $existing->update(['invoice_number' => $this->voidedInvoiceNumber($existing)]);

            return $number;
        }

        // Another invoice (same or different sale) already owns AR-{order_num}.
        // Never reclaim it for insert/restore — that hits uq_org_customer_invoice_number.
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
