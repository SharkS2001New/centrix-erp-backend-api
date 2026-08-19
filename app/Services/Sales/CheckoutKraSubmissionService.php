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

        $sale->loadMissing('items');
        $lines = $sale->items;
        if ($lines->isEmpty()) {
            return null;
        }

        $orderItems = $lines->map(fn ($line) => [
            'product_name' => $line->product_name ?? $line->product_code,
            'product_code' => $line->product_code,
            'quantity' => (float) $line->quantity,
            'amount' => (float) $line->amount,
            'product_vat' => (float) ($line->product_vat ?? 0),
        ])->all();

        $invoiceNumber = 'POS-'.$sale->order_num;
        try {
            $service = KraDeviceService::fromSettings($finance);
            $invoiceNumber = $service->traderInvoiceForSale($sale, $finance);
            $result = $service->sendSale(
                $orderItems,
                (float) $sale->order_total,
                $invoiceNumber,
                $buyerPin,
            );
        } catch (\Throwable $e) {
            Log::warning('KRA device call threw during checkout — sale kept without fiscalization', [
                'sale_id' => $sale->id,
                'message' => $e->getMessage(),
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
