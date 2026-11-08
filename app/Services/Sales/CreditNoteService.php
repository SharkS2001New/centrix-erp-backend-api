<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\KraResponse;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Kra\KraAutoRetryPolicy;
use App\Services\Kra\KraDeviceErrorTranslator;
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

    /**
     * Create the Centrix credit note for an approved return.
     *
     * @param  bool  $deferKra  When true (default), do not call the device inside the approve
     *                          DB transaction — mark pending and let afterResponse / cron retry
     *                          (same smoothness as checkout soft-fail).
     */
    public function createForReturn(
        CustomerReturn $return,
        User $user,
        array $financeSettings,
        bool $deferKra = true,
    ): CreditNote {
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

        if ($deferKra) {
            $creditNote->update([
                'kra_status' => 'pending',
                'kra_relevant_invoice_number' => $relevantInvoice,
                'kra_error_message' => self::pendingRetryMessage('Fiscalization runs after approve so the till is not blocked.'),
            ]);

            return $creditNote->fresh() ?? $creditNote;
        }

        return $this->submitToKra($creditNote, $return, $financeSettings);
    }

    /**
     * User-facing copy when credit note fiscalization is deferred.
     */
    public static function pendingRetryMessage(?string $detail = null): string
    {
        $base = 'Return approved. KRA credit note is queued and will retry automatically until Centrix KRA Agent / Comstore succeed.';
        $detail = trim((string) $detail);
        if ($detail === '') {
            return $base;
        }

        return $base.' ('.$detail.')';
    }

    /**
     * One-shot KRA attempt for a credit note (afterResponse job, manual Retry, or cron).
     */
    public function attemptKraForCreditNote(CreditNote|int $creditNote): CreditNote
    {
        $creditNote = $creditNote instanceof CreditNote
            ? $creditNote
            : CreditNote::query()->findOrFail((int) $creditNote);

        if ((string) $creditNote->kra_status === 'success') {
            return $creditNote;
        }

        if ((string) $creditNote->kra_status === 'skipped') {
            return $creditNote;
        }

        $return = CustomerReturn::query()
            ->with(['lines.product.vat', 'sale', 'customer'])
            ->find($creditNote->customer_return_id);
        if (! $return || $return->status !== 'approved') {
            return $creditNote;
        }

        $organization = Organization::query()->find($creditNote->organization_id);
        if (! $organization) {
            return $creditNote;
        }

        $finance = (new CapabilityGate($organization))->moduleSettings('finance') ?? [];
        if (empty($finance['enable_kra_device'])) {
        return $creditNote;
        }

        $updated = $this->submitToKra($creditNote, $return, $finance);

        if (in_array((string) $updated->kra_status, ['failed', 'pending'], true)) {
            Log::warning('KRA soft-fail on return credit note — return stays approved', [
                'sale_id' => $return->sale_id,
                'customer_return_id' => $return->id,
                'credit_note_id' => $updated->id,
                'return_kind' => $return->return_kind,
                'kra_status' => $updated->kra_status,
                'message' => $updated->kra_error_message,
            ]);
        }

        return $updated;
    }

    /**
     * Retry credit notes waiting on KRA (pending / transient failed).
     *
     * @return array{attempted: int, succeeded: int, still_pending: int, skipped: int}
     */
    public function retryPendingKraCredits(int $limit = 50, ?int $organizationId = null): array
    {
        $stats = ['attempted' => 0, 'succeeded' => 0, 'still_pending' => 0, 'skipped' => 0];

        $query = CreditNote::query()
            ->whereIn('kra_status', ['pending', 'failed'])
            ->whereNotNull('customer_return_id')
            ->whereNotNull('kra_relevant_invoice_number')
            ->where('kra_relevant_invoice_number', '!=', '')
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('id')
            ->limit(max(1, $limit));

        $notes = $query->get();
        foreach ($notes as $creditNote) {
            if ((string) $creditNote->kra_status === 'failed'
                && ! KraDeviceErrorTranslator::isTransientConnectivityFailure($creditNote->kra_error_message)) {
                $stats['skipped']++;

                continue;
            }

            $retryMeta = KraAutoRetryPolicy::initializePayload(
                is_array($creditNote->kra_response_payload) ? $creditNote->kra_response_payload : [],
            );
            if (! KraAutoRetryPolicy::shouldAutoRetry($retryMeta)) {
                $stats['skipped']++;
                if (KraAutoRetryPolicy::isExhausted($retryMeta)) {
                    $creditNote->update([
                        'kra_response_payload' => array_merge($retryMeta, ['auto_retry_exhausted' => true]),
                    ]);
                }
                continue;
            }

            $retryMeta = KraAutoRetryPolicy::markAttempt($retryMeta);
            $creditNote->update(['kra_response_payload' => $retryMeta]);

            $stats['attempted']++;
            $updated = $this->attemptKraForCreditNote($creditNote->fresh() ?? $creditNote);
            if ((string) $updated->kra_status === 'success') {
                $stats['succeeded']++;
            } else {
                $payload = is_array($updated->kra_response_payload) ? $updated->kra_response_payload : [];
                foreach (['auto_retry_count', 'auto_retry_last_at', 'auto_retry_exhausted', 'auto_retry_exhausted_at'] as $key) {
                    if (array_key_exists($key, $retryMeta)) {
                        $payload[$key] = $retryMeta[$key];
                    }
                }
                $updated->update(['kra_response_payload' => $payload]);
                $stats['still_pending']++;
            }
        }

        $fiscalCredits = KraResponse::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('response_payload->document_type', 'credit_note')
            ->where('response_payload->source', 'kra_invoice_credit')
            ->where('response_payload->soft_failed', true)
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($fiscalCredits as $attemptRow) {
            $attemptPayload = is_array($attemptRow->response_payload) ? $attemptRow->response_payload : [];
            $failure = $attemptPayload['technical_message'] ?? $attemptRow->error_message;
            if ($attemptRow->status === 'failed'
                && ! KraDeviceErrorTranslator::isTransientConnectivityFailure($failure)) {
                $stats['skipped']++;
                continue;
            }

            $attemptPayload = KraAutoRetryPolicy::initializePayload($attemptPayload);
            if (! KraAutoRetryPolicy::shouldAutoRetry($attemptPayload)) {
                $stats['skipped']++;
                continue;
            }

            $sale = Sale::query()
                ->with(['items.product.vat', 'customer'])
                ->find((int) $attemptRow->sale_id);
            $sourceId = (int) ($attemptPayload['source_kra_response_id'] ?? 0);
            $source = $sourceId > 0 ? KraResponse::query()->find($sourceId) : null;
            $organization = $sale ? Organization::query()->find((int) $sale->organization_id) : null;
            if (! $sale || ! $source || ! $organization) {
                $stats['skipped']++;
                continue;
            }

            $finance = (new CapabilityGate($organization))->moduleSettings('finance') ?? [];
            if (empty($finance['enable_kra_device']) || ! empty($finance['kra_device_test_mode'])) {
                $stats['skipped']++;
                continue;
            }

            $attemptPayload = KraAutoRetryPolicy::markAttempt($attemptPayload);
            $attemptRow->response_payload = $attemptPayload;
            $attemptRow->save();

            $stats['attempted']++;
            try {
                $updated = $this->fiscalCreditSaleOnly(
                    $sale,
                    null,
                    $finance,
                    is_string($attemptPayload['refund_reason_code'] ?? null)
                        ? $attemptPayload['refund_reason_code']
                        : null,
                    $source,
                    $attemptRow,
                );
                if ($updated->status === 'success') {
                    $stats['succeeded']++;
                } elseif ($updated->status === 'pending') {
                    $stats['still_pending']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['still_pending']++;
                Log::warning('KRA fiscal-only credit retry failed', [
                    'kra_response_id' => $attemptRow->id,
                    'sale_id' => $sale->id,
                    'organization_id' => $sale->organization_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
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

        $startedAt = microtime(true);
        $maxSeconds = \App\Services\Kra\KraAgentBridge::COMMAND_WAIT_SECONDS + 10;

        try {
            $service = KraDeviceService::fromSettings(
                $financeSettings,
                (int) ($return->organization_id ?? $sale?->organization_id ?? 0) ?: null,
            );
            $orderItems = $return->lines
                ->filter(fn ($line) => (float) $line->amount > 0)
                ->map(function ($line) {
                    $qty = (float) $line->return_qty > 0
                        ? (float) $line->return_qty
                        : 1.0;
                    $amount = (float) $line->amount;
                    $vatRate = \App\Services\Kra\SalesVatCalculator::vatRateFromProduct($line->product);
                    $productVat = $vatRate > 0
                        ? \App\Services\Kra\SalesVatCalculator::vatFromInclusiveGross($amount, $vatRate)
                        : 0.0;

                    return [
                        'product_name' => $line->product_name ?? $line->product_code ?? 'Credit adjustment',
                        'product_code' => $line->product_code ?: 'CREDIT-ADJ',
                        'quantity' => max(0.001, $qty),
                        'amount' => $amount,
                        'product_vat' => $productVat,
                        'vat_rate' => $vatRate,
                    ];
                })
                ->values()
                ->all();

            // Amount-only credit notes (no product lines) — single adjustment PLU for the device.
            if ($orderItems === [] && (float) $return->total_amount > 0) {
                $amount = round((float) $return->total_amount, 2);
                $orderItems = [[
                    'product_name' => $return->reason ?: 'Credit adjustment',
                    'product_code' => 'CREDIT-ADJ',
                    'quantity' => 1.0,
                    'amount' => $amount,
                    'product_vat' => 0.0,
                ]];
            }

            if ($this->creditNoteNeedsFreshTraderInvoice($creditNote)) {
                $stored = $creditNote->kra_request_payload;
                if (is_array($stored)) {
                    unset($stored['sign_structure']['TraderSystemInvoiceNumber']);
                    $creditNote->kra_request_payload = $stored;
                }
            }

            $invoiceNumber = $service->traderInvoiceForCreditNote($creditNote, $financeSettings);
            $buyerPin = $return->customer?->kra_pin ?? $return->sale?->customer?->kra_pin ?? null;

            $remaining = static function () use ($startedAt, $maxSeconds): int {
                return max(1, (int) floor($maxSeconds - (microtime(true) - $startedAt)));
            };
            $deviceContext = static function (): array {
                return [
                    'agent_wait_seconds' => \App\Services\Kra\KraAgentBridge::COMMAND_WAIT_SECONDS,
                    'http_timeout_seconds' => 50,
                    'http_connect_timeout_seconds' => 3,
                ];
            };

            // Soft path: fail fast when agent/Comstore known down — same as checkout.
            $preflight = $service->agentFiscalPreflight();
            if ($preflight['ready'] === false
                || ($preflight['ready'] === null && ! $service->agentIsWarm())) {
                $detail = KraDeviceErrorTranslator::userMessageForDocument(
                    $preflight['message'] ?? 'Centrix KRA Agent / Comstore is not available.',
                    'credit_note',
                );

                return $this->markCreditNoteSoftOutcome(
                    $creditNote,
                    $return,
                    $relevantInvoice,
                    'pending',
                    self::pendingRetryMessage($detail),
                    null,
                    null,
                );
            }

            if ((microtime(true) - $startedAt) >= $maxSeconds) {
                return $this->markCreditNoteSoftOutcome(
                    $creditNote,
                    $return,
                    $relevantInvoice,
                    'pending',
                    self::pendingRetryMessage(
                        KraDeviceErrorTranslator::userMessageForDocument(
                            'KRA did not respond in time.',
                            'credit_note',
                        ),
                    ),
                    null,
                    null,
                );
            }

            $result = $service->sendCreditNote(
                $orderItems,
                (float) $return->total_amount,
                $invoiceNumber,
                $relevantInvoice,
                KraRefundReasonMapper::fromReturnReason($return->reason),
                $return->refund_method,
                $buyerPin,
                $deviceContext(),
            );

            $mapped = $result['response'] ?? [];

            if (! ($result['success'] ?? false)) {
                $rawMessage = (string) ($result['message'] ?? 'KRA credit note failed');
                $userMessage = KraDeviceErrorTranslator::userMessageForDocument($rawMessage, 'credit_note');
                $queued = KraDeviceErrorTranslator::isTransientConnectivityFailure($rawMessage);

                return $this->markCreditNoteSoftOutcome(
                    $creditNote,
                    $return,
                    $relevantInvoice,
                    $queued ? 'pending' : 'failed',
                    $queued ? self::pendingRetryMessage($userMessage) : $userMessage,
                    $result['payload'] ?? null,
                    $mapped,
                );
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
                'kra_response_payload' => array_merge($mapped, [
                    'document_type' => 'credit_note',
                    'online_upload_status' => $mapped['online_upload_status']
                        ?? ((is_string($mapped['transmit_status'] ?? null)
                            && str_contains(strtolower($mapped['transmit_status']), 'transmitted to etims'))
                            && ! str_contains(strtolower($mapped['transmit_status']), 'not yet')
                            ? 'transmitted'
                            : 'pending'),
                ]),
                'kra_error_message' => null,
            ]);

            $this->persistCreditKraResponse($return, $creditNote->fresh() ?? $creditNote, [
                'invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
                'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
                'signature_link' => $mapped['signature_link'] ?? null,
                'serial_number' => $mapped['serial_number'] ?? null,
                'kra_timestamp' => $mapped['timestamp'] ?? null,
                'request_payload' => $result['payload'] ?? null,
                'response_payload' => array_merge($mapped, [
                    'document_type' => 'credit_note',
                    'online_upload_status' => $mapped['online_upload_status']
                        ?? ((is_string($mapped['transmit_status'] ?? null)
                            && str_contains(strtolower($mapped['transmit_status']), 'transmitted to etims'))
                            && ! str_contains(strtolower($mapped['transmit_status']), 'not yet')
                            ? 'transmitted'
                            : 'pending'),
                    'relevant_invoice_number' => $relevantInvoice,
                    'customer_return_id' => $return->id,
                    'credit_note_id' => $creditNote->id,
                ]),
                'status' => 'success',
                'error_message' => null,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->markCreditNoteSoftOutcome(
                $creditNote,
                $return,
                $relevantInvoice,
                'failed',
                $e->getMessage(),
                null,
                null,
            );
        } catch (\Throwable $e) {
            Log::error('KRA credit note exception: ' . $e->getMessage(), [
                'credit_note_id' => $creditNote->id,
                'exception' => $e,
            ]);

            return $this->markCreditNoteSoftOutcome(
                $creditNote,
                $return,
                $relevantInvoice,
                'pending',
                self::pendingRetryMessage(
                    'Could not complete the KRA credit note response. Will retry automatically.',
                ),
                null,
                null,
            );
        }

        return $creditNote->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    protected function markCreditNoteSoftOutcome(
        CreditNote $creditNote,
        CustomerReturn $return,
        string $relevantInvoice,
        string $status,
        string $message,
        ?array $requestPayload,
        ?array $responsePayload,
    ): CreditNote {
        $payload = is_array($responsePayload) ? $responsePayload : [];
        if (in_array($status, ['pending', 'failed'], true)) {
            $payload = KraAutoRetryPolicy::initializePayload($payload);
        }

        $creditNote->update([
            'kra_status' => $status,
            'kra_relevant_invoice_number' => $relevantInvoice,
            'kra_request_payload' => $requestPayload,
            'kra_response_payload' => $payload !== [] ? $payload : $responsePayload,
            'kra_error_message' => $message,
        ]);

        $fresh = $creditNote->fresh() ?? $creditNote;
        $this->persistCreditKraResponse($return, $fresh, [
            'invoice_number' => $fresh->kra_invoice_number
                ?: ('CN-PENDING-'.$fresh->id),
            'receipt_signature' => null,
            'signature_link' => null,
            'serial_number' => null,
            'kra_timestamp' => null,
            'request_payload' => $requestPayload,
            'response_payload' => array_merge(is_array($responsePayload) ? $responsePayload : [], [
                'document_type' => 'credit_note',
                'relevant_invoice_number' => $relevantInvoice,
                'customer_return_id' => $return->id,
                'credit_note_id' => $fresh->id,
                'soft_failed' => true,
            ]),
            'status' => $status === 'pending' ? 'failed' : $status,
            'error_message' => $message,
        ]);

        Log::warning('KRA credit note soft outcome for return ' . $return->return_no, [
            'credit_note_id' => $fresh->id,
            'kra_status' => $status,
            'message' => $message,
        ]);

        return $fresh;
    }

    /**
     * Keep one soft-fail / success kra_responses row linked to this credit note for Retry UI.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function persistCreditKraResponse(
        CustomerReturn $return,
        CreditNote $creditNote,
        array $attributes,
    ): void {
        if (! $return->sale_id) {
            return;
        }

        $attributes['sale_id'] = (int) $return->sale_id;
        $attributes['organization_id'] = (int) $return->organization_id;
        $attributes['order_no'] = $return->sale?->order_num ?? 0;

        $existing = KraResponse::query()
            ->where('sale_id', $return->sale_id)
            ->where(function ($q) use ($creditNote) {
                $q->where('response_payload->credit_note_id', $creditNote->id)
                    ->orWhere('response_payload->customer_return_id', $creditNote->customer_return_id);
            })
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();

            return;
        }

        try {
            KraResponse::create($attributes);
        } catch (\Throwable $e) {
            Log::warning('Could not persist kra_responses row for credit note soft-fail', [
                'credit_note_id' => $creditNote->id,
                'message' => $e->getMessage(),
            ]);
        }
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
        ?User $user,
        array $financeSettings,
        ?string $refundReasonCode = null,
        ?KraResponse $sourceResponse = null,
        ?KraResponse $attemptRow = null,
    ): KraResponse {
        if (empty($financeSettings['enable_kra_device'])) {
            throw new InvalidArgumentException('Enable KRA device in Finance settings first.');
        }

        $sale->loadMissing(['items.product', 'customer']);
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
        if ($attemptRow && ((int) $attemptRow->sale_id !== (int) $sale->id
            || ! $this->kraResponseIsCreditNoteDocument($attemptRow))) {
            throw new InvalidArgumentException('KRA credit attempt does not belong to this sale.');
        }
        if ($attemptRow?->status === 'success') {
            throw new InvalidArgumentException('This KRA credit note has already succeeded.');
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

        $existingPayload = is_array($attemptRow?->request_payload) ? $attemptRow->request_payload : [];
        $existingTraderNumber = trim((string) data_get($existingPayload, 'sign_structure.TraderSystemInvoiceNumber', ''));

        $service = KraDeviceService::fromSettings(
            $financeSettings,
            (int) ($sale->organization_id ?? 0) ?: null,
        );
        $invoiceNumber = $existingTraderNumber !== ''
            ? $existingTraderNumber
            : app(KraTraderInvoiceAllocator::class)->forOrganization((int) $sale->organization_id);

        // Match original payable total (post order_discount) so credit = what eTIMS booked.
        $orderItems = \App\Services\Kra\KraSaleFiscalBuilder::orderItemsFromSale($sale);

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
            $errorMessage = (string) ($result['message'] ?? 'KRA device rejected the credit note.');
            $pending = KraDeviceErrorTranslator::isTransientConnectivityFailure(
                $result['technical_message'] ?? $errorMessage,
            );
            $attributes = [
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
                        'refund_reason_code' => $reasonCode,
                        'soft_failed' => $pending,
                        'technical_message' => $result['technical_message'] ?? $errorMessage,
                        'error_code' => $result['error_code'] ?? null,
                    ],
                ),
                'status' => $pending ? 'pending' : 'failed',
                'error_message' => $pending
                    ? 'Credit note is queued for automatic retry: '.$errorMessage
                    : $errorMessage,
            ];
            $failed = $attemptRow ?? new KraResponse;
            $failed->fill($attributes);
            $failed->save();

            if ($pending) {
                return $failed->fresh() ?? $failed;
            }

            KraDeviceFailure::abort((string) ($failed->error_message ?: 'KRA device rejected the credit note.'));
        }

        $mapped = $result['response'] ?? [];

        $attributes = [
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
            'error_message' => null,
        ];
        $creditResponse = $attemptRow ?? new KraResponse;
        $creditResponse->fill($attributes);
        $creditResponse->save();

        return $creditResponse->fresh() ?? $creditResponse;
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

    /**
    * Return the original eTIMS control-unit receipt used by Comstore for credit notes.
    * The Comstore 3.6.3 contract explicitly requires cu-inv-no without leading zeros.
     */
    protected function relevantInvoiceNumberFromKraResponse(KraResponse $kra): string
    {
        $payload = is_array($kra->response_payload) ? $kra->response_payload : [];
        $cuInv = trim((string) ($payload['cu_inv_no'] ?? $payload['cu-inv-no'] ?? ''));
        if ($cuInv !== '') {
            if (str_contains($cuInv, '/')) {
                $cuInv = trim(substr($cuInv, (int) strrpos($cuInv, '/') + 1));
            }
            $trimmed = ltrim($cuInv, '0');

            return $trimmed !== '' ? $trimmed : $cuInv;
        }

        $request = is_array($kra->request_payload) ? $kra->request_payload : [];
        $sign = is_array($request['sign_structure'] ?? null) ? $request['sign_structure'] : [];
        $trader = $this->shortReceiptReference((string) ($sign['TraderSystemInvoiceNumber'] ?? ''));
        if ($trader !== null) {
            return $trader;
        }

        $column = $this->shortReceiptReference((string) ($kra->invoice_number ?? ''));
        if ($column !== null) {
            return $column;
        }

        $responseInvoice = $this->shortReceiptReference((string) ($payload['invoice_number'] ?? ''));
        if ($responseInvoice !== null) {
            return $responseInvoice;
        }

        $fromQr = $this->receiptNumberFromEtimsPayload($kra);
        if ($fromQr !== '') {
            return $fromQr;
        }

        return '';
    }

    /**
     * Comstore receipt numbers are numeric and at most 10 digits.
     * CN-PENDING placeholders and the long CU invoice are not receipts.
     */
    protected function shortReceiptReference(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, 'CN-PENDING')) {
            return null;
        }
        if (strlen($trimmed) > 10 || ! ctype_digit($trimmed)) {
            return null;
        }
        $normalized = ltrim($trimmed, '0');

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * eTIMS QR payload: date, time, control-unit serial, receipt number, URL.
     */
    protected function receiptNumberFromEtimsPayload(KraResponse $kra): string
    {
        $payload = is_array($kra->response_payload) ? $kra->response_payload : [];
        $candidates = [
            $payload['signature_link'] ?? null,
            $payload['receipt_signature'] ?? null,
            $kra->signature_link ?? null,
            $kra->receipt_signature ?? null,
        ];

        foreach ($candidates as $raw) {
            $text = trim((string) $raw);
            if ($text === '') {
                continue;
            }
            if (preg_match('/\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}(?::\d{2})?\s+\S+\s+0*(\d{1,10})\b/', $text, $match)) {
                $number = ltrim($match[1], '0');

                return $number !== '' ? $number : '0';
            }
        }

        return '';
    }

    /**
     * A previous credit note was rejected because its own receipt reference was bad.
     * The next try must not send that same trader invoice number again.
     */
    protected function creditNoteNeedsFreshTraderInvoice(CreditNote $creditNote): bool
    {
        $message = trim((string) $creditNote->kra_error_message);
        if ($message === '') {
            return false;
        }

        return (bool) preg_match(
            '/receiptNo\s+is\s+error|\(Code\s*314\)|\bCode\s*314\b|\(Code\s*12\)|\(Code\s*994\)|duplicate\s+invoice|receipt or invoice reference was rejected|not valid for this credit note|fresh receipt reference/i',
            $message,
        );
    }
}
