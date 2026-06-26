<?php

namespace App\Services\Kra;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class KraDeviceService
{
    public function __construct(
        protected string $deviceBaseUrl,
        protected string $serialNumber,
        protected string $pinNumber,
        protected bool $isTest = false,
    ) {}

    public static function fromSettings(array $financeSettings): self
    {
        $base = trim((string) ($financeSettings['kra_device_ip'] ?? ''));
        if ($base === '') {
            throw new InvalidArgumentException('KRA device IP / URL is not configured.');
        }

        if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
            $base = 'http://' . $base;
        }

        return new self(
            rtrim($base, '/'),
            trim((string) ($financeSettings['kra_serial_number'] ?? '')),
            trim((string) ($financeSettings['kra_pin_number'] ?? '')),
            (bool) ($financeSettings['kra_device_test_mode'] ?? config('app.env') !== 'production'),
        );
    }

    public function sendSale(array $orderItems, float $totalAmount, string $invoiceNumber, ?string $buyerPin = null): array
    {
        $this->assertDeviceConfigured();

        $payload = $this->buildWorkflowPayload(
            $orderItems,
            $totalAmount,
            $invoiceNumber,
            'original',
            '',
            '',
            $buyerPin,
            $this->paymentAmountsForMethod($totalAmount, 'CASH'),
        );

        return $this->postToDevice('/api/complete-workflow', $payload, [
            'invoice' => $invoiceNumber,
            'document_type' => 'sale',
        ]);
    }

    /**
     * Submit a credit note (Comstore complete-workflow with InvoiceType = credit).
     *
     * @param  array<int, array<string, mixed>>  $orderItems
     */
    public function sendCreditNote(
        array $orderItems,
        float $totalAmount,
        string $invoiceNumber,
        string $relevantInvoiceNumber,
        string $refundReasonCode,
        ?string $refundMethod = 'CASH',
        ?string $buyerPin = null,
    ): array {
        $this->assertDeviceConfigured();

        $payload = $this->buildWorkflowPayload(
            $orderItems,
            $totalAmount,
            $invoiceNumber,
            'credit',
            $relevantInvoiceNumber,
            $refundReasonCode,
            $buyerPin,
            $this->paymentAmountsForMethod($totalAmount, $refundMethod),
        );

        return $this->postToDevice('/api/complete-workflow', $payload, [
            'invoice' => $invoiceNumber,
            'document_type' => 'credit_note',
            'relevant_invoice' => $relevantInvoiceNumber,
        ]);
    }

    /** @return array{cash: float, card: float, check: float} */
    protected function paymentAmountsForMethod(float $totalAmount, ?string $refundMethod): array
    {
        $method = strtoupper(trim((string) $refundMethod));
        $formatted = round($totalAmount, 2);

        if ($method === 'CASH') {
            return ['cash' => $formatted, 'card' => 0.0, 'check' => 0.0];
        }

        return ['cash' => 0.0, 'card' => $formatted, 'check' => 0.0];
    }

    /**
     * Register catalogue products on the on-prem KRA device (LightStores PLU upload).
     *
     * @param  iterable<int, object>  $products
     */
    public function registerProducts(iterable $products, string $path = '/api/register-plu'): array
    {
        $this->assertDeviceConfigured();

        $items = is_array($products) ? $products : iterator_to_array($products);
        if ($items === []) {
            return [
                'success' => false,
                'message' => 'No products to register.',
                'registered_count' => 0,
                'product_count' => 0,
            ];
        }

        $path = $path !== '' ? $path : '/api/register-plu';
        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $registered = 0;
        $chunks = array_chunk($items, 200);

        foreach ($chunks as $index => $chunk) {
            $pluData = array_map(fn ($product) => self::buildPluLineFromProduct($product), $chunk);
            $payload = $this->buildProductRegisterPayload($pluData, $index + 1, $path);
            $result = $this->postToDevice($path, $payload, [
                'batch' => $index + 1,
                'plu_count' => count($pluData),
            ]);

            if (! $result['success']) {
                return array_merge($result, [
                    'registered_count' => $registered,
                    'product_count' => count($items),
                    'batch' => $index + 1,
                ]);
            }

            $registered += count($chunk);
        }

        return [
            'success' => true,
            'message' => "Registered {$registered} product(s) on KRA device.",
            'registered_count' => $registered,
            'product_count' => count($items),
            'response' => $result['response'] ?? null,
        ];
    }

    public function generateInvoiceNumber(): string
    {
        return substr(time() . rand(100, 999), 0, 10);
    }

    /** @return array<string, string> */
    public static function buildPluLineFromProduct(object $product): array
    {
        $price = (float) ($product->unit_price ?? $product->last_selling_price ?? 0);

        return self::buildPluLine(
            (string) ($product->product_name ?? 'Product'),
            (string) ($product->product_code ?? ''),
            $price,
            1,
        );
    }

    /** @return array<string, string> */
    public static function buildPluLine(
        string $itemName,
        string $barcode,
        float $unitPrice,
        float $quantity = 1,
        float $amount = null,
    ): array {
        $qty = max(0.001, $quantity);
        $lineAmount = $amount ?? ($unitPrice * $qty);

        return [
            'item_Name' => $itemName !== '' ? $itemName : 'Product',
            'Barcode' => $barcode,
            'SalePrice' => number_format($unitPrice, 2, '.', ''),
            'SaleQty' => number_format($qty, 2, '.', ''),
            'SaleAmount' => number_format($lineAmount, 2, '.', ''),
            'ItemDisCount(%)' => '0',
            'ItemDisCount' => '0',
            'Schg' => '0',
            'Levy' => '0',
        ];
    }

    protected function assertDeviceConfigured(): void
    {
        if ($this->serialNumber === '') {
            throw new InvalidArgumentException('KRA device serial number is not configured.');
        }

        if ($this->pinNumber === '') {
            throw new InvalidArgumentException('KRA shop PIN is not configured.');
        }
    }

    /** @param  array<int, array<string, string>>  $pluData */
    protected function buildProductRegisterPayload(array $pluData, int $batch = 1, string $path = ''): array
    {
        $signStructure = $this->buildProductRegisterSignStructure($batch);

        if ($this->usesUploadPluDataPayload($path)) {
            return [
                'Sn' => $this->serialNumber,
                'IsTest' => $this->isTest,
                'PluItems' => $pluData,
                'SignStructure' => $signStructure,
            ];
        }

        return [
            'sn' => $this->serialNumber,
            'is_test' => $this->isTest,
            'plu_data' => $pluData,
            'sign_structure' => $signStructure,
        ];
    }

    protected function usesUploadPluDataPayload(string $path): bool
    {
        $normalized = strtolower($path);

        return str_contains($normalized, 'upload-plu');
    }

    /** @param  array<string, mixed>|null  $responseData */
    protected function deviceResponseSuccessful(\Illuminate\Http\Client\Response $response, ?array $responseData, string $path): bool
    {
        if (! $response->successful()) {
            return false;
        }

        if ($this->usesUploadPluDataPayload($path)) {
            if (! is_array($responseData)) {
                return true;
            }

            foreach (['success', 'Success'] as $key) {
                if (array_key_exists($key, $responseData) && $responseData[$key] === false) {
                    return false;
                }
            }

            return true;
        }

        return (bool) ($responseData['success'] ?? false);
    }

    /** @return array<string, string> */
    protected function buildProductRegisterSignStructure(int $batch): array
    {
        return [
            'SignType' => '2',
            'DiscAmt' => '0',
            'CashAmt' => '0',
            'CheckAmt' => '0',
            'CardAmt' => '0',
            'InvoiceType' => 'original',
            'relevantInvoiceNumber' => '',
            'pinOfBuyer' => '',
            'exemptionNumber' => '',
            'pinOfshop' => $this->pinNumber,
            'TraderSystemInvoiceNumber' => 'PLU-' . date('YmdHis') . '-' . $batch,
            'Vat A(Exempt) net' => '0.00',
            'Vat A(Exempt) value' => '0.00',
            'Vat B(16.00%) net' => '0.00',
            'Vat B(16.00%) value' => '0.00',
            'Vat C(0%) net' => '0.00',
            'Vat C(0%) value' => '0.00',
            'Vat D(Non-VAT) net' => '0.00',
            'Vat D(Non-VAT) value' => '0.00',
            'Vat E(8%) net' => '',
            'Vat E(8%) value' => '',
            'Schg F(10.00%) net' => '',
            'Schg F(10.00%) value' => '',
            'Levy G(2.00%) net' => '',
            'Levy G(2.00%) value' => '',
            'rfdRsnCd' => '',
            'NetTotal' => '0.00',
            'EXCHANGERate' => '',
        ];
    }

    protected function buildWorkflowPayload(
        array $orderItems,
        float $totalAmount,
        string $invoiceNumber,
        string $invoiceType,
        string $relevantInvoiceNumber,
        string $refundReasonCode,
        ?string $buyerPin,
        array $paymentAmounts,
    ): array {
        $summary = SalesVatCalculator::summarizeForKra($orderItems);
        $vat16NetTotal = $summary['vat16_net'];
        $vat16ValueTotal = $summary['vat16_value'];
        $vatExemptNetTotal = $summary['exempt_net'];

        $pluData = [];
        foreach ($orderItems as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $quantity = max(0.001, (float) ($item['quantity'] ?? 1));
            $unitPrice = $quantity > 0 ? $amount / $quantity : $amount;

            $pluData[] = self::buildPluLine(
                (string) ($item['product_name'] ?? 'Product'),
                (string) ($item['product_code'] ?? ''),
                $unitPrice,
                $quantity,
                $amount,
            );
        }

        $netTotal = $vatExemptNetTotal + $vat16NetTotal;
        $calculatedTotal = $netTotal + $vat16ValueTotal;
        if (abs($totalAmount - $calculatedTotal) > 0.01) {
            $vat16ValueTotal = round($totalAmount - $netTotal, 2);
        }

        $signStructure = [
            'SignType' => $this->isTest ? '0' : '1',
            'DiscAmt' => '0',
            'CashAmt' => number_format($paymentAmounts['cash'], 2, '.', ''),
            'CheckAmt' => number_format($paymentAmounts['check'], 2, '.', ''),
            'CardAmt' => number_format($paymentAmounts['card'], 2, '.', ''),
            'InvoiceType' => $invoiceType,
            'relevantInvoiceNumber' => $relevantInvoiceNumber,
            'pinOfBuyer' => $buyerPin ?? '',
            'exemptionNumber' => '',
            'pinOfshop' => $this->pinNumber,
            'TraderSystemInvoiceNumber' => $invoiceNumber,
            'Vat A(Exempt) net' => number_format($vatExemptNetTotal, 2, '.', ''),
            'Vat A(Exempt) value' => '0.00',
            'Vat B(16.00%) net' => number_format($vat16NetTotal, 2, '.', ''),
            'Vat B(16.00%) value' => number_format($vat16ValueTotal, 2, '.', ''),
            'Vat C(0%) net' => '0.00',
            'Vat C(0%) value' => '0.00',
            'Vat D(Non-VAT) net' => '0.00',
            'Vat D(Non-VAT) value' => '0.00',
            'Vat E(8%) net' => '',
            'Vat E(8%) value' => '',
            'Schg F(10.00%) net' => '',
            'Schg F(10.00%) value' => '',
            'Levy G(2.00%) net' => '',
            'Levy G(2.00%) value' => '',
            'rfdRsnCd' => $refundReasonCode,
            'NetTotal' => number_format($netTotal, 2, '.', ''),
            'EXCHANGERate' => '',
            'Schg' => '0',
            'Levy' => '0',
        ];

        return [
            'sn' => $this->serialNumber,
            'is_test' => $this->isTest,
            'plu_data' => $pluData,
            'sign_structure' => $signStructure,
        ];
    }

    /** @deprecated Use buildWorkflowPayload */
    protected function buildSalePayload(array $orderItems, float $totalAmount, string $invoiceNumber, ?string $buyerPin): array
    {
        return $this->buildWorkflowPayload(
            $orderItems,
            $totalAmount,
            $invoiceNumber,
            'original',
            '',
            '',
            $buyerPin,
            $this->paymentAmountsForMethod($totalAmount, 'CASH'),
        );
    }

    /** Probe the on-prem device health endpoint (GET /api/health). */
    public function checkHealth(): array
    {
        $url = $this->deviceBaseUrl.'/api/health';

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get($url);

            $body = $response->json();
            $successful = $response->successful();

            return [
                'success' => $successful,
                'reachable' => true,
                'http_status' => $response->status(),
                'url' => $url,
                'message' => $successful
                    ? (is_array($body) ? (string) ($body['message'] ?? 'KRA device health check passed.') : 'KRA device health check passed.')
                    : 'KRA device health check failed (HTTP '.$response->status().').',
                'response' => is_array($body) ? $body : ['body' => $response->body()],
            ];
        } catch (\Throwable $e) {
            Log::warning('KRA device health check failed: '.$e->getMessage(), ['url' => $url]);

            return [
                'success' => false,
                'reachable' => false,
                'http_status' => null,
                'url' => $url,
                'message' => 'Could not reach KRA device: '.$e->getMessage(),
                'response' => null,
            ];
        }
    }

    /** @param  array<string, mixed>  $context */
    protected function postToDevice(string $path, array $payload, array $context = []): array
    {
        $url = $this->deviceBaseUrl . $path;

        try {
            $response = Http::timeout(60)
                ->retry(2, 200)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);

            $responseData = $response->json();

            return [
                'success' => $this->deviceResponseSuccessful($response, is_array($responseData) ? $responseData : null, $path),
                'message' => is_array($responseData)
                    ? ($responseData['message'] ?? $responseData['Message'] ?? $response->body())
                    : $response->body(),
                'payload' => $payload,
                'response' => $this->mapResponse($responseData),
            ];
        } catch (\Throwable $e) {
            Log::error('KRA device API error: ' . $e->getMessage(), array_merge([
                'url' => $url,
            ], $context));

            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'payload' => $payload,
                'response' => null,
            ];
        }
    }

    protected function mapResponse(?array $responseData): ?array
    {
        if (! $responseData) {
            return null;
        }

        return [
            'success' => $responseData['success'] ?? false,
            'message' => $responseData['message'] ?? '',
            'signature' => $responseData['signature'] ?? null,
            'serial_number' => $responseData['serial_number'] ?? null,
            'timestamp' => $responseData['timestamp'] ?? null,
            'signature_file_path' => $responseData['signature_file_path'] ?? null,
            'invoice_number' => $responseData['invoice_number'] ?? null,
            'scu_id' => $responseData['scu_id'] ?? null,
            'cu_inv_no' => $responseData['cu-inv-no'] ?? null,
            'internal_data' => $responseData['internal-data'] ?? null,
            'receipt_signature' => $responseData['Receipt Signature'] ?? null,
            'signature_link' => $responseData['signature_link'] ?? null,
            'version' => $responseData['version'] ?? null,
        ];
    }
}
