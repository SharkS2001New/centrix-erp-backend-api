<?php

namespace App\Services\Sales;

use App\Models\ActionRequest;
use App\Models\CreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\CustomerReturn;
use App\Models\JournalEntry;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\StockReservation;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanently removes a terminal sale (cancelled / expired) and linked residues.
 * Does not reverse stock again — cancel/expire paths already restored inventory.
 * Inventory ledger rows are left for reconciliation history.
 */
class SaleHardDeleteService
{
    public function __construct(protected AuditLogger $audit) {}

    public function delete(Sale $sale, ?User $actor = null): void
    {
        $saleId = (int) $sale->id;
        $status = (string) $sale->status;

        if (! in_array($status, ['cancelled', 'expired'], true)) {
            throw new \InvalidArgumentException(
                "Only cancelled or expired sales can be hard-deleted (got [{$status}])."
            );
        }

        DB::transaction(function () use ($sale, $saleId, $actor) {
            $this->deleteKraResponses($saleId);
            $this->deleteCustomerInvoices($saleId);
            $this->deleteReturnsAndCreditNotes($saleId);
            $this->deleteJournals($saleId);
            $this->deleteActionRequests($saleId);
            $this->clearSoftLinks($saleId);

            StockReservation::query()
                ->where('sale_id', $saleId)
                ->delete();

            $snapshot = [
                'id' => $saleId,
                'order_num' => $sale->order_num,
                'organization_id' => $sale->organization_id,
                'status' => $sale->status,
                'order_total' => $sale->order_total,
            ];

            $sale->delete();

            if ($actor) {
                $this->audit->log(
                    $actor,
                    'hard_delete',
                    'sales',
                    (string) $saleId,
                    $snapshot,
                    null,
                );
            }
        });
    }

    protected function deleteKraResponses(int $saleId): void
    {
        if (! Schema::hasTable('kra_responses')) {
            return;
        }

        KraResponse::query()->where('sale_id', $saleId)->delete();
    }

    protected function deleteCustomerInvoices(int $saleId): void
    {
        if (! Schema::hasTable('customer_invoices')) {
            return;
        }

        $invoiceIds = CustomerInvoice::query()
            ->where('sale_id', $saleId)
            ->pluck('id')
            ->all();

        if ($invoiceIds === []) {
            return;
        }

        if (Schema::hasTable('customer_invoice_payments')) {
            CustomerInvoicePayment::query()
                ->whereIn('customer_invoice_id', $invoiceIds)
                ->delete();
        }

        CustomerInvoice::query()->whereIn('id', $invoiceIds)->delete();
    }

    protected function deleteReturnsAndCreditNotes(int $saleId): void
    {
        if (Schema::hasTable('credit_notes')) {
            CreditNote::query()->where('sale_id', $saleId)->delete();
        }

        if (Schema::hasTable('customer_returns')) {
            CustomerReturn::query()->where('sale_id', $saleId)->delete();
        }

        if (Schema::hasTable('returns')) {
            DB::table('returns')->where('sale_id', $saleId)->delete();
        }
    }

    protected function deleteJournals(int $saleId): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $entryIds = JournalEntry::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->pluck('id')
            ->all();

        if ($entryIds === []) {
            return;
        }

        if (Schema::hasTable('journal_entry_lines')) {
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->delete();
        }

        JournalEntry::query()->whereIn('id', $entryIds)->delete();
    }

    protected function deleteActionRequests(int $saleId): void
    {
        if (! Schema::hasTable('action_requests')) {
            return;
        }

        ActionRequest::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->delete();
    }

    protected function clearSoftLinks(int $saleId): void
    {
        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'superseded_sale_id')) {
            TemporaryCart::query()
                ->where('superseded_sale_id', $saleId)
                ->update(['superseded_sale_id' => null]);
        }

        if (Schema::hasTable('mpesa_incoming_payments') && Schema::hasColumn('mpesa_incoming_payments', 'applied_sale_id')) {
            DB::table('mpesa_incoming_payments')
                ->where('applied_sale_id', $saleId)
                ->update(['applied_sale_id' => null]);
        }
    }
}
