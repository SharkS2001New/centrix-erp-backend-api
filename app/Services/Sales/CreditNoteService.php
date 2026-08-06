<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\User;
use App\Services\Kra\KraDeviceFailure;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraRefundReasonMapper;
use App\Services\Kra\KraTraderInvoiceAllocator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CreditNoteService
{
    public function nextCreditNoteNo(int $organizationId): string
    {
        $last = CreditNote::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('credit_note_no');

        $next = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        // Prefer org-local sequence, but also skip CN numbers already used by any
        // other tenant while a leftover global unique index remains in production.
        while ($this->isCreditNoteNoUnavailable($organizationId, $next)) {
            $next++;
        }

        return $this->formatCreditNoteNo($next);
    }

    public function formatCreditNoteNo(int $sequence): string
    {
        return 'CN-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function isCreditNoteNoUnavailable(int $organizationId, int $seq): bool
    {
        $creditNoteNo = $this->formatCreditNoteNo($seq);

        if (CreditNote::query()
            ->where('organization_id', $organizationId)
            ->where('credit_note_no', $creditNoteNo)
            ->exists()) {
            return true;
        }

        return CreditNote::query()
            ->where('credit_note_no', $creditNoteNo)
            ->where('organization_id', '!=', $organizationId)
            ->exists();
    }

    public function createForReturn(CustomerReturn $return, User $user, array $financeSettings): CreditNote
    {
        $return->loadMissing(['lines.product.vat', 'sale', 'customer']);

        $creditNote = CreditNote::create([
            'credit_note_no' => $this->nextCreditNoteNo((int) $user->organization_id),
            'customer_return_id' => $return->id,
            'organization_id' => $user->organization_id,
            'branch_id' => $return->branch_id,
            'sale_id' => $return->sale_id,
            'customer_num' => $return->customer_num,
            'credit_date' => $return->return_date,
            'total_amount' => $return->total_amount,
            'refund_method' => $return->refund_method,
            'reason' => $return->reason,
            'notes' => $return->notes,
            'kra_status' => 'skipped',
            'kra_refund_reason_code' => KraRefundReasonMapper::fromReturnReason($return->reason),
        ]);

        if (empty($financeSettings['enable_kra_device']) || ! $return->sale_id) {
            return $creditNote;
        }

        // Device configured org-wide does not mean this sale was fiscalized (bypass,
        // fiscalization off, pre-KRA sale, failed/skipped submit). Only credit notes
        // for fiscalized originals should hit the device — otherwise keep skipped.
        $relevantInvoice = $this->resolveRelevantInvoiceNumber($return);
        if ($relevantInvoice === '') {
            if ($return->return_kind === 'legacy') {
                KraDeviceFailure::abort('Original sale has no KRA invoice number to credit.');
            }

            return $creditNote;
        }

        // Sale already credited on KRA (e.g. "Credit This Sale") — Centrix return only.
        $return->loadMissing('sale');
        if ($return->sale) {
            $existingCredit = $this->findSuccessfulFiscalCredit($return->sale, $relevantInvoice);
            if ($existingCredit) {
                return $this->attachExistingFiscalCredit($creditNote, $existingCredit, $relevantInvoice, $return);
            }
        }

        $creditNote = $this->submitToKra($creditNote, $return, $financeSettings);

        if ($creditNote->kra_status === 'failed') {
            KraDeviceFailure::abort((string) ($creditNote->kra_error_message ?: 'KRA device rejected the credit note.'));
        }

        return $creditNote;
    }

    public function submitToKra(CreditNote $creditNote, CustomerReturn $return, array $financeSettings): CreditNote
    {
        $relevantInvoice = $this->resolveRelevantInvoiceNumber($return);
        if ($relevantInvoice === '') {
            if ($return->return_kind === 'legacy') {
                $creditNote->update([
                    'kra_status' => 'failed',
                    'kra_error_message' => 'Original sale has no KRA invoice number to credit.',
                ]);
            }

            return $creditNote->fresh() ?? $creditNote;
        }

        $sale = $return->sale ?? ($return->sale_id ? Sale::query()->find($return->sale_id) : null);
        if ($sale) {
            $existingCredit = $this->findSuccessfulFiscalCredit($sale, $relevantInvoice);
            if ($existingCredit) {
                return $this->attachExistingFiscalCredit($creditNote, $existingCredit, $relevantInvoice, $return);
            }
        }

        try {
            $service = KraDeviceService::fromSettings($financeSettings);
            $orderItems = $return->lines
                ->filter(fn ($line) => (float) $line->return_qty > 0)
                ->map(function ($line) {
                    $qty = max(0.001, (float) $line->return_qty);
                    $amount = (float) $line->amount;
                    $vatRate = \App\Services\Kra\SalesVatCalculator::vatRateFromProduct($line->product);
                    $productVat = $vatRate > 0
                        ? \App\Services\Kra\SalesVatCalculator::vatFromInclusiveGross($amount, $vatRate)
                        : 0.0;

                    return [
                        'product_name' => $line->product_name ?? $line->product_code,
                        'product_code' => $line->product_code,
                        'quantity' => $qty,
                        'amount' => $amount,
                        'product_vat' => $productVat,
                    ];
                })
                ->values()
                ->all();

            $invoiceNumber = $service->traderInvoiceForCreditNote($creditNote, $financeSettings);
            $buyerPin = $return->customer?->kra_pin ?? $return->sale?->customer?->kra_pin ?? null;

            $result = $service->sendCreditNote(
                $orderItems,
                (float) $return->total_amount,
                $invoiceNumber,
                $relevantInvoice,
                KraRefundReasonMapper::fromReturnReason($return->reason),
                $return->refund_method,
                $buyerPin,
            );

            $mapped = $result['response'] ?? [];

            if (! ($result['success'] ?? false)) {
                $creditNote->update([
                    'kra_status' => 'failed',
                    'kra_relevant_invoice_number' => $relevantInvoice,
                    'kra_request_payload' => $result['payload'] ?? null,
                    'kra_response_payload' => $mapped,
                    'kra_error_message' => $result['message'] ?? 'KRA credit note failed',
                ]);

                Log::warning('KRA credit note failed for return ' . $return->return_no, [
                    'credit_note_id' => $creditNote->id,
                    'message' => $result['message'] ?? null,
                ]);

                return $creditNote->fresh();
            }

            $creditNote->update([
                'kra_status' => 'success',
                'kra_relevant_invoice_number' => $relevantInvoice,
                'kra_invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
                'kra_cu_inv_no' => $mapped['cu_inv_no'] ?? null,
                'kra_receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
                'kra_signature_link' => $mapped['signature_link'] ?? null,
                'kra_serial_number' => $mapped['serial_number'] ?? null,
                'kra_timestamp' => $mapped['timestamp'] ?? null,
                'kra_request_payload' => $result['payload'] ?? null,
                'kra_response_payload' => $mapped,
                'kra_error_message' => null,
            ]);

            KraResponse::create([
                'sale_id' => $return->sale_id,
                'organization_id' => (int) $return->organization_id,
                'order_no' => $return->sale?->order_num ?? 0,
                'invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
                'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
                'signature_link' => $mapped['signature_link'] ?? null,
                'serial_number' => $mapped['serial_number'] ?? null,
                'kra_timestamp' => $mapped['timestamp'] ?? null,
                'request_payload' => $result['payload'] ?? null,
                'response_payload' => array_merge($mapped, [
                    'document_type' => 'credit_note',
                    'relevant_invoice_number' => $relevantInvoice,
                    'customer_return_id' => $return->id,
                    'credit_note_id' => $creditNote->id,
                ]),
                'status' => 'success',
            ]);
        } catch (InvalidArgumentException $e) {
            $creditNote->update([
                'kra_status' => 'failed',
                'kra_error_message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $creditNote->update([
                'kra_status' => 'failed',
                'kra_error_message' => 'Could not save the KRA credit note response. Please try again or contact support.',
            ]);
            Log::error('KRA credit note exception: ' . $e->getMessage(), [
                'credit_note_id' => $creditNote->id,
                'exception' => $e,
            ]);
        }

        return $creditNote->fresh();
    }

    public function relevantInvoiceFromKraResponse(KraResponse $kra): string
    {
        return $this->relevantInvoiceNumberFromKraResponse($kra);
    }

    public function isCreditNoteDocument(KraResponse $kra): bool
    {
        return $this->kraResponseIsCreditNoteDocument($kra);
    }

    /**
     * Submit a KRA credit for an already-fiscalized sale without creating a Centrix
     * customer return / credit note. The Centrix sale stays as-is; only the device is credited.
     */
    public function fiscalCreditSaleOnly(
        Sale $sale,
        User $user,
        array $financeSettings,
        ?string $refundReasonCode = null,
        ?KraResponse $sourceResponse = null,
    ): KraResponse {
        if (empty($financeSettings['enable_kra_device'])) {
            throw new InvalidArgumentException('Enable KRA device in Finance settings first.');
        }

        $sale->loadMissing(['items', 'customer']);
        if ($sale->items->isEmpty()) {
            throw new InvalidArgumentException('Sale has no line items to credit on KRA.');
        }

        $source = $sourceResponse;
        if ($source && (int) $source->sale_id !== (int) $sale->id) {
            throw new InvalidArgumentException('KRA response does not belong to this sale.');
        }
        if ($source && $this->kraResponseIsCreditNoteDocument($source)) {
            throw new InvalidArgumentException('This KRA row is already a credit note.');
        }
        if ($source && $source->status !== 'success') {
            throw new InvalidArgumentException('Only successful KRA invoices can be credited.');
        }

        $relevantInvoice = $source
            ? $this->relevantInvoiceNumberFromKraResponse($source)
            : $this->resolveOriginalInvoiceNumberForSale($sale);

        if ($relevantInvoice === '') {
            throw new InvalidArgumentException('Original sale has no KRA invoice number to credit.');
        }

        if ($this->saleAlreadyHasSuccessfulFiscalCredit($sale, $relevantInvoice)) {
            throw new InvalidArgumentException(
                'This sale already has a successful KRA credit note for that invoice.',
            );
        }

        $reasonCode = KraRefundReasonMapper::normalizeCode($refundReasonCode)
            ?? KraRefundReasonMapper::fromReturnReason(null);

        $service = KraDeviceService::fromSettings($financeSettings);
        $invoiceNumber = app(KraTraderInvoiceAllocator::class)
            ->forOrganization((int) $sale->organization_id);

        $orderItems = $sale->items->map(fn ($line) => [
            'product_name' => $line->product_name ?? $line->product_code,
            'product_code' => $line->product_code,
            'quantity' => (float) $line->quantity,
            'amount' => (float) $line->amount,
            'product_vat' => (float) ($line->product_vat ?? 0),
        ])->all();

        $buyerPin = trim((string) ($sale->customer?->kra_pin ?? ''));
        $buyerPin = $buyerPin !== '' ? $buyerPin : null;

        $result = $service->sendCreditNote(
            $orderItems,
            (float) $sale->order_total,
            $invoiceNumber,
            $relevantInvoice,
            $reasonCode,
            'CASH',
            $buyerPin,
        );

        if (! ($result['success'] ?? false)) {
            $failed = KraResponse::create([
                'sale_id' => $sale->id,
                'organization_id' => (int) $sale->organization_id,
                'order_no' => $this->displayOrderNoForSale($sale),
                'invoice_number' => $invoiceNumber,
                'request_payload' => $result['payload'] ?? null,
                'response_payload' => array_merge(
                    is_array($result['response'] ?? null) ? $result['response'] : [],
                    [
                        'document_type' => 'credit_note',
                        'source' => 'kra_invoice_credit',
                        'relevant_invoice_number' => $relevantInvoice,
                        'source_kra_response_id' => $source?->id,
                    ],
                ),
                'status' => 'failed',
                'error_message' => $result['message'] ?? 'KRA device rejected the credit note.',
            ]);

            KraDeviceFailure::abort((string) ($failed->error_message ?: 'KRA device rejected the credit note.'));
        }

        $mapped = $result['response'] ?? [];

        return KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => (int) $sale->organization_id,
            'order_no' => $this->displayOrderNoForSale($sale),
            'invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
            'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
            'signature_link' => $mapped['signature_link'] ?? null,
            'serial_number' => $mapped['serial_number'] ?? null,
            'kra_timestamp' => $mapped['timestamp'] ?? null,
            'request_payload' => $result['payload'] ?? null,
            'response_payload' => array_merge(is_array($mapped) ? $mapped : [], [
                'document_type' => 'credit_note',
                'source' => 'kra_invoice_credit',
                'relevant_invoice_number' => $relevantInvoice,
                'source_kra_response_id' => $source?->id,
                'refund_reason_code' => $reasonCode,
            ]),
            'status' => 'success',
        ]);
    }

    protected function resolveOriginalInvoiceNumberForSale(Sale $sale): string
    {
        $stored = trim((string) (($sale->fulfillment_meta ?? [])['legacy_kra_invoice_number'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $candidates = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'success')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $kra) {
            if ($this->kraResponseIsCreditNoteDocument($kra)) {
                continue;
            }
            $invoice = $this->relevantInvoiceNumberFromKraResponse($kra);
            if ($invoice !== '') {
                return $invoice;
            }
        }

        return '';
    }

    protected function saleAlreadyHasSuccessfulFiscalCredit(Sale $sale, string $relevantInvoice): bool
    {
        return $this->findSuccessfulFiscalCredit($sale, $relevantInvoice) !== null;
    }

    protected function findSuccessfulFiscalCredit(?Sale $sale, string $relevantInvoice): ?KraResponse
    {
        if (! $sale?->id || $relevantInvoice === '') {
            return null;
        }

        $rows = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            if (! $this->kraResponseIsCreditNoteDocument($row)) {
                continue;
            }
            $payload = is_array($row->response_payload) ? $row->response_payload : [];
            $source = strtolower(trim((string) ($payload['source'] ?? '')));
            $linked = trim((string) ($payload['relevant_invoice_number'] ?? ''));
            $request = is_array($row->request_payload) ? $row->request_payload : [];
            $sign = is_array($request['sign_structure'] ?? null) ? $request['sign_structure'] : [];
            $fromSign = trim((string) ($sign['relevantInvoiceNumber'] ?? ''));

            if ($linked === $relevantInvoice || $fromSign === $relevantInvoice) {
                return $row;
            }

            // Fiscal-only "Credit This Sale" rows may omit relevant invoice on older payloads.
            if ($source === 'kra_invoice_credit') {
                return $row;
            }
        }

        return null;
    }

    /**
     * Reuse an existing KRA credit (e.g. from "Credit This Sale") on a Centrix return
     * without calling the device again.
     */
    protected function attachExistingFiscalCredit(
        CreditNote $creditNote,
        KraResponse $existingCredit,
        string $relevantInvoice,
        CustomerReturn $return,
    ): CreditNote {
        $payload = is_array($existingCredit->response_payload) ? $existingCredit->response_payload : [];

        $creditNote->update([
            'kra_status' => 'success',
            'kra_relevant_invoice_number' => $relevantInvoice,
            'kra_invoice_number' => $existingCredit->invoice_number,
            'kra_cu_inv_no' => $payload['cu_inv_no'] ?? $payload['cu-inv-no'] ?? null,
            'kra_receipt_signature' => $existingCredit->receipt_signature,
            'kra_signature_link' => $existingCredit->signature_link,
            'kra_serial_number' => $existingCredit->serial_number,
            'kra_timestamp' => $existingCredit->kra_timestamp,
            'kra_request_payload' => $existingCredit->request_payload,
            'kra_response_payload' => array_merge($payload, [
                'document_type' => 'credit_note',
                'reused_kra_response_id' => $existingCredit->id,
                'skip_reason' => 'already_credited_on_kra',
                'customer_return_id' => $return->id,
                'credit_note_id' => $creditNote->id,
            ]),
            'kra_error_message' => null,
        ]);

        Log::info('Skipped KRA credit note send — sale already credited on device', [
            'credit_note_id' => $creditNote->id,
            'customer_return_id' => $return->id,
            'sale_id' => $return->sale_id,
            'reused_kra_response_id' => $existingCredit->id,
            'relevant_invoice' => $relevantInvoice,
        ]);

        return $creditNote->fresh() ?? $creditNote;
    }

    protected function displayOrderNoForSale(Sale $sale): int
    {
        if (strtolower((string) $sale->channel) === 'pos' && $sale->pos_order_num) {
            return (int) $sale->pos_order_num;
        }

        return (int) $sale->order_num;
    }

    protected function resolveRelevantInvoiceNumber(CustomerReturn $return): string
    {
        $provided = trim((string) ($return->kra_original_invoice_number ?? ''));
        if ($provided !== '') {
            return $provided;
        }

        $return->loadMissing('sale');
        $sale = $return->sale;
        if ($sale) {
            $stored = trim((string) (($sale->fulfillment_meta ?? [])['legacy_kra_invoice_number'] ?? ''));
            if ($stored !== '') {
                return $stored;
            }
        }

        if (! $return->sale_id) {
            return '';
        }

        // Prefer the original sale fiscalization — never a later credit-note row on the
        // same sale_id (orderByDesc used to pick those and break subsequent returns).
        $candidates = KraResponse::query()
            ->where('sale_id', $return->sale_id)
            ->where('status', 'success')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $kra) {
            if ($this->kraResponseIsCreditNoteDocument($kra)) {
                continue;
            }

            $invoice = $this->relevantInvoiceNumberFromKraResponse($kra);
            if ($invoice !== '') {
                return $invoice;
            }
        }

        return '';
    }

    protected function kraResponseIsCreditNoteDocument(KraResponse $kra): bool
    {
        $payload = is_array($kra->response_payload) ? $kra->response_payload : [];
        $docType = strtolower(trim((string) ($payload['document_type'] ?? '')));
        if (in_array($docType, ['credit_note', 'credit', 'creditnote'], true)) {
            return true;
        }

        $request = is_array($kra->request_payload) ? $kra->request_payload : [];
        $sign = is_array($request['sign_structure'] ?? null) ? $request['sign_structure'] : [];
        $invoiceType = strtolower(trim((string) ($sign['InvoiceType'] ?? '')));

        return in_array($invoiceType, ['credit', 'credit_note', 'creditnote'], true);
    }

    protected function relevantInvoiceNumberFromKraResponse(KraResponse $kra): string
    {
        $payload = $kra->response_payload ?? [];
        $cuInv = trim((string) ($payload['cu_inv_no'] ?? $payload['cu-inv-no'] ?? ''));
        if ($cuInv !== '') {
            $trimmed = ltrim($cuInv, '0');

            return $trimmed !== '' ? $trimmed : $cuInv;
        }

        return trim((string) ($kra->invoice_number ?? ''));
    }
}
