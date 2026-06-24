<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\KraResponse;
use App\Models\User;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraRefundReasonMapper;
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

        return 'CN-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
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

        return $this->submitToKra($creditNote, $return, $financeSettings);
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

            return $creditNote->fresh();
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

            $invoiceNumber = $service->generateInvoiceNumber();
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
                'order_no' => $return->sale?->order_num ?? 0,
                'invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
                'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
                'signature_link' => $mapped['signature_link'] ?? null,
                'serial_number' => $mapped['serial_number'] ?? null,
                'kra_timestamp' => $mapped['timestamp'] ?? null,
                'request_payload' => $result['payload'] ?? null,
                'response_payload' => array_merge($mapped, [
                    'document_type' => 'credit_note',
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
                'kra_error_message' => $e->getMessage(),
            ]);
            Log::error('KRA credit note exception: ' . $e->getMessage(), [
                'credit_note_id' => $creditNote->id,
            ]);
        }

        return $creditNote->fresh();
    }

    public function relevantInvoiceFromKraResponse(KraResponse $kra): string
    {
        return $this->relevantInvoiceNumberFromKraResponse($kra);
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

        $originalKra = KraResponse::query()
            ->where('sale_id', $return->sale_id)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->first();

        if (! $originalKra) {
            return '';
        }

        return $this->relevantInvoiceNumberFromKraResponse($originalKra);
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
