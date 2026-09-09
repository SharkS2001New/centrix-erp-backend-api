<?php

namespace App\Services\Sales;

use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Erp\CapabilityGate;
use App\Services\Kra\KraDeviceErrorTranslator;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraFiscalPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * KRA fiscalization for checkout — shared by sync checkout and after-response POS jobs.
 */
class CheckoutKraSubmissionService
{
    public function submitForSale(
        Sale $sale,
        CapabilityGate $gate,
        ?string $buyerPin = null,
    ): ?KraResponse {
        $finance = $gate->moduleSettings('finance');
        if (empty($finance['enable_kra_device'])) {
            return null;
        }

        if ($sale->kraResponse()->where('status', 'success')->exists()) {
            return $sale->kraResponse()->where('status', 'success')->latest('id')->first();
        }

        $sale->loadMissing(['items.product']);
        $lines = $sale->items;
        if ($lines->isEmpty()) {
            return null;
        }

        $orderItems = $lines->map(fn ($line) => [
            'product_name' => $line->resolvedProductName(),
            'product_code' => $line->product_code,
            'quantity' => (float) $line->quantity,
            'amount' => (float) $line->amount,
            'product_vat' => (float) ($line->product_vat ?? 0),
        ])->all();

        $invoiceNumber = 'POS-'.$sale->order_num;
        $startedAt = microtime(true);
        $maxSeconds = \App\Services\Kra\KraAgentBridge::CHECKOUT_MAX_SECONDS;
        try {
            $service = KraDeviceService::fromSettings(
                $finance,
                $gate->organization()?->id ? (int) $gate->organization()->id : null,
            );

            $remaining = static function () use ($startedAt, $maxSeconds): int {
                return max(1, (int) floor($maxSeconds - (microtime(true) - $startedAt)));
            };

            $checkoutContext = static function () use ($remaining): array {
                $left = $remaining();

                return [
                    'checkout' => true,
                    'agent_wait_seconds' => min(
                        \App\Services\Kra\KraAgentBridge::CHECKOUT_COMMAND_WAIT_SECONDS,
                        $left,
                    ),
                    'http_timeout_seconds' => min(18, $left),
                    'http_connect_timeout_seconds' => 2,
                ];
            };

            $result = null;
            $preflight = $service->agentFiscalPreflight();

            // Speed path: when the agent heartbeat already says Comstore is up, skip the
            // extra /api/health hop and go straight to complete-workflow (one agent RTT).
            // Otherwise run a short health gate — recovers when Comstore was just started,
            // and avoids queuing complete-workflow while Comstore is still down.
            $needsHealthGate = $preflight['ready'] !== true;
            if ($needsHealthGate) {
                $healthWait = min(
                    \App\Services\Kra\KraAgentBridge::CHECKOUT_HEALTH_WAIT_SECONDS,
                    $remaining(),
                );
                $health = $service->checkHealth($healthWait);
                if (! ($health['success'] ?? false)) {
                    $healthMessage = trim((string) ($health['message'] ?? ''));
                    if ($healthMessage === '' && $preflight['ready'] === false) {
                        $healthMessage = trim((string) ($preflight['message'] ?? ''));
                    }
                    if ($healthMessage === '') {
                        $healthMessage = $preflight['ready'] === false
                            ? 'Comstore or the KRA fiscal device is not available on the shop PC.'
                            : 'Comstore is not responding. Start Comstore on the shop PC, then try again.';
                    }
                    Log::warning('KRA soft-skip on checkout — health failed; skipping complete-workflow', [
                        'sale_id' => $sale->id,
                        'message' => $healthMessage,
                        'preflight_ready' => $preflight['ready'],
                        'reachable' => $health['reachable'] ?? null,
                        'device_connection' => $health['device_connection'] ?? null,
                        'manual_start_required' => $health['manual_start_required'] ?? null,
                        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ]);
                    $result = [
                        'success' => false,
                        'message' => KraDeviceErrorTranslator::userMessage($healthMessage),
                        'payload' => null,
                        'response' => is_array($health['response'] ?? null) ? $health['response'] : null,
                    ];
                }
            } else {
                Log::debug('KRA checkout skipping health gate — agent heartbeat reports Comstore OK', [
                    'sale_id' => $sale->id,
                ]);
            }

            if ($result === null && (microtime(true) - $startedAt) >= $maxSeconds) {
                Log::warning('KRA soft-skip on checkout — wall-clock budget exhausted before fiscalize', [
                    'sale_id' => $sale->id,
                    'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
                $result = [
                    'success' => false,
                    'message' => KraDeviceErrorTranslator::userMessage(
                        'KRA did not respond in time. Sale saved without fiscal QR.',
                    ),
                    'payload' => null,
                    'response' => null,
                ];
            }

            if ($result === null) {
                $invoiceNumber = $service->traderInvoiceForSale($sale, $finance);
                $result = $service->sendSale(
                    $orderItems,
                    (float) $sale->order_total,
                    $invoiceNumber,
                    $buyerPin,
                    $checkoutContext(),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('KRA device call threw during checkout — sale kept without fiscalization', [
                'sale_id' => $sale->id,
                'message' => $e->getMessage(),
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
            $result = [
                'success' => false,
                'message' => KraDeviceErrorTranslator::userMessage(
                    'Could not reach KRA device: '.$e->getMessage(),
                ),
                'payload' => null,
                'response' => null,
            ];
        }

        if (! ($result['success'] ?? false)) {
            $message = trim((string) ($result['message'] ?? 'KRA device submission failed.'));
            if ($message === '') {
                $message = 'KRA device submission failed.';
            }

            Log::warning('KRA soft-fail on checkout — sale saved without fiscal QR', [
                'sale_id' => $sale->id,
                'message' => $message,
            ]);

            return $this->persistResponse($sale, [
                'order_no' => $this->displayOrderNo($sale),
                'invoice_number' => $invoiceNumber,
                'receipt_signature' => null,
                'signature_link' => null,
                'serial_number' => null,
                'kra_timestamp' => null,
                'request_payload' => $result['payload'] ?? null,
                'response_payload' => array_merge(
                    is_array($result['response'] ?? null) ? $result['response'] : [],
                    [
                        'document_type' => 'sale',
                        'soft_failed' => true,
                        // Keep raw device text so the failure dialog can highlight the exact PLU.
                        'technical_message' => $result['technical_message'] ?? null,
                        'error_code' => $result['error_code'] ?? null,
                    ],
                ),
                'status' => 'failed',
                'error_message' => $message,
            ]);
        }

        $mapped = $result['response'] ?? [];

        return $this->persistResponse($sale, [
            'order_no' => $this->displayOrderNo($sale),
            'invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
            'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
            'signature_link' => $mapped['signature_link'] ?? null,
            'serial_number' => $mapped['serial_number'] ?? null,
            'kra_timestamp' => $mapped['timestamp'] ?? null,
            'request_payload' => $result['payload'] ?? null,
            'response_payload' => array_merge(is_array($mapped) ? $mapped : [], [
                'document_type' => 'sale',
            ]),
            'status' => 'success',
            'error_message' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $finance
     */
    public function recordAmountBypass(Sale $sale, array $finance): KraResponse
    {
        $threshold = KraFiscalPolicy::bypassAboveAmount($finance);
        $thresholdLabel = $threshold !== null
            ? number_format($threshold, 2, '.', ',')
            : '0.00';
        $totalLabel = number_format((float) $sale->order_total, 2, '.', ',');
        $message = sprintf(
            'Sale created without KRA: order total (KES %s) meets the KRA amount bypass limit (KES %s or above).',
            $totalLabel,
            $thresholdLabel,
        );

        Log::info('KRA amount bypass on checkout — sale saved without fiscalization', [
            'sale_id' => $sale->id,
            'order_total' => (float) $sale->order_total,
            'bypass_above' => $threshold,
        ]);

        return $this->persistResponse($sale, [
            'order_no' => $this->displayOrderNo($sale),
            'invoice_number' => 'BYPASS-'.$sale->order_num,
            'receipt_signature' => null,
            'signature_link' => null,
            'serial_number' => null,
            'kra_timestamp' => null,
            'request_payload' => [
                'document_type' => 'sale',
                'skip_reason' => 'amount_bypass',
                'order_total' => (float) $sale->order_total,
                'kra_bypass_above_amount' => $threshold,
            ],
            'response_payload' => [
                'document_type' => 'sale',
                'skipped' => true,
                'skip_reason' => 'amount_bypass',
            ],
            'status' => 'skipped',
            'error_message' => $message,
        ]);
    }

    /**
     * One kra_responses row per sale (and per org invoice #). Retries must update the
     * failed row — inserting again hits uq_org_kra_invoice_number after a CU success.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function persistResponse(Sale $sale, array $attributes): KraResponse
    {
        $attributes['sale_id'] = (int) $sale->id;
        $attributes['organization_id'] = (int) $sale->organization_id;

        $existing = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        try {
            return KraResponse::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            $invoiceNumber = trim((string) ($attributes['invoice_number'] ?? ''));
            $conflict = $invoiceNumber !== ''
                ? KraResponse::query()
                    ->where('organization_id', (int) $sale->organization_id)
                    ->where('invoice_number', $invoiceNumber)
                    ->first()
                : null;

            if ($conflict && (int) $conflict->sale_id === (int) $sale->id) {
                $conflict->fill($attributes);
                $conflict->save();

                return $conflict;
            }

            throw $e;
        }
    }

    public function displayOrderNo(Sale $sale): int
    {
        if (strtolower((string) $sale->channel) === 'pos' && $sale->pos_order_num) {
            return (int) $sale->pos_order_num;
        }

        return (int) $sale->order_num;
    }
}
