<?php

namespace App\Services\Sales;

use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\Erp\CapabilityGate;
use App\Services\Kra\KraDeviceErrorTranslator;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraFiscalPolicy;
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

        if ($sale->kraResponses()->whereRaw("LOWER(COALESCE(status, '')) = 'success'")->whereNotNull('signature_link')->where('signature_link', '!=', '')->exists()) {
            return $sale->kraResponses()
                ->whereRaw("LOWER(COALESCE(status, '')) = 'success'")
                ->whereNotNull('signature_link')
                ->where('signature_link', '!=', '')
                ->latest('id')
                ->first();
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

            return KraResponse::create([
                'sale_id' => $sale->id,
                'organization_id' => (int) $sale->organization_id,
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

        $row = KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => (int) $sale->organization_id,
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
        ]);

        app(CompletedSalesCacheService::class)->invalidateForSale($sale);

        return $row;
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

        return KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => (int) $sale->organization_id,
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

    public function displayOrderNo(Sale $sale): int
    {
        if (strtolower((string) $sale->channel) === 'pos' && $sale->pos_order_num) {
            return (int) $sale->pos_order_num;
        }

        return (int) $sale->order_num;
    }
}
