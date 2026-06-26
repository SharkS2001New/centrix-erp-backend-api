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
        );

        return $this->postToDevice('/api/complete-workflow', $payload, [
            'invoice' => $invoiceNumber,
            'document_type' => 'sale',
        ]);
    }

    /**
     * Submit a credit note via the same complete-workflow payload as a sale.
     *
     * Identical to sendSale except sign_structure uses InvoiceType "credit",
     * plus relevantInvoiceNumber and rfdRsnCd required by Comstore.
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
        );

        return $this->postToDevice('/api/complete-workflow', $payload, [
            'invoice' => $invoiceNumber,
            'document_type' => 'credit_note',
            'relevant_invoice' => $relevantInvoiceNumber,
        ]);
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

        if ($this->usesUploadPluDataPayload($path)) {
            foreach ($items as $index => $product) {
                $pluItem = self::buildComstorePluItemFromProduct($product);
                $payload = $this->buildComstoreUploadPayload($pluItem);
                $result = $this->postToDevice($path, $payload, [
                    'batch' => $index + 1,
                    'product_code' => (string) ($product->product_code ?? ''),
                    'plu_count' => 1,
                ]);

                if (! $result['success']) {
                    return array_merge($result, [
                        'registered_count' => $registered,
                        'product_count' => count($items),
                        'batch' => $index + 1,
                        'product_code' => (string) ($product->product_code ?? ''),
                    ]);
                }

                $registered++;
            }
        } else {
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
    public static function buildComstorePluItemFromProduct(object $product): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('erp.module_settings_defaults.finance.kra_plu_defaults', []);
        $price = (float) ($product->unit_price ?? $product->last_selling_price ?? 0);
        $productCode = trim((string) ($product->product_code ?? ''));
        $barcodePrefix = (string) ($defaults['barcode_prefix'] ?? '000000');
        $pluNo = (string) ($defaults['plu_no'] ?? $product->id ?? $productCode);
        $barcode = $barcodePrefix.$productCode;
        $pluName = trim((string) ($product->product_name ?? 'Product'));
        $uploadUnitPrice = (string) ($defaults['unit_price'] ?? '1');

        if ($pluName === '') {
            $pluName = 'Product';
        }

        return [
            'plu_no' => $pluNo,
            'barcode' => $barcode,
            'plu_name' => mb_substr($pluName, 0, 50),
            'unit_price' => $uploadUnitPrice !== '' ? $uploadUnitPrice : self::formatComstoreUnitPrice($price),
            'item_cls_code' => (string) ($defaults['item_cls_code'] ?? '99010000'),
            'pkg_unit_cd' => (string) ($defaults['pkg_unit_cd'] ?? 'BG-Bag'),
            'qty_unit_cd' => (string) ($defaults['qty_unit_cd'] ?? 'U-Pieces/item [Number]'),
            'orgn_nat_cd' => (string) ($defaults['orgn_nat_cd'] ?? 'KE-KENYA'),
            'btch_no' => '0',
            'add_info' => '',
            'tax_type' => self::resolveComstoreTaxType($product),
            'sfty_qty' => '0',
            'type_code' => (string) ($defaults['type_code'] ?? '02Finished Product'),
            'isrc_aplcb_yn' => '0',
            'change_qty' => (string) ($defaults['change_qty'] ?? '100000'),
            'stocks' => '0',
            'use_yor_n' => '1',
        ];
    }

    protected static function formatComstoreUnitPrice(float $price): string
    {
        if ($price == floor($price)) {
            return (string) (int) $price;
        }

        return number_format($price, 2, '.', '');
    }

    protected static function resolveComstoreTaxType(object $product): string
    {
        $vat = $product->vat ?? null;
        if ($vat === null) {
            return 'B-16.00%';
        }

        $pct = (float) ($vat->vat_percentage ?? 16);

        if ($pct <= 0) {
            return 'A-Exempt';
        }

        return 'B-16.00%';
    }

    /** @return array<string, mixed> */
    protected function buildComstoreUploadPayload(array $pluItem): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('erp.module_settings_defaults.finance.kra_plu_defaults', []);

        return [
            'sn' => $this->serialNumber,
            'plu_items' => [$pluItem],
            'from_no' => (int) ($defaults['from_no'] ?? 1),
            'end_no' => (int) ($defaults['end_no'] ?? 100000),
            'update_flag' => (int) ($defaults['update_flag'] ?? 0),
            'file_signal' => '',
        ];
    }

    /** @return array<string, string> */
    public static function buildWorkflowPluLine(array $item): array
    {
        $amount = (float) ($item['amount'] ?? 0);
        $quantity = max(0.001, (float) ($item['quantity'] ?? 1));
        $unitPrice = $quantity > 0 ? $amount / $quantity : $amount;
        $itemName = trim((string) ($item['product_name'] ?? 'Product'));

        return [
            'item_Name' => $itemName !== '' ? $itemName : 'Product',
            'Barcode' => '',
            'SalePrice' => number_format($unitPrice, 2, '.', ''),
            'SaleQty' => self::formatWorkflowSaleQty($quantity),
            'SaleAmount' => number_format($amount, 2, '.', ''),
            'ItemDisCount(%)' => '0',
            'ItemDisCount' => '0',
            'Schg' => '0',
            'Levy' => '0',
        ];
    }

    protected static function formatWorkflowSaleQty(float $quantity): string
    {
        if (abs($quantity - round($quantity)) < 0.00001) {
            return (string) (int) round($quantity);
        }

        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
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

    /** @param  array<int, array<string, mixed>>  $pluData */
    protected function buildProductRegisterPayload(array $pluData, int $batch = 1, string $path = ''): array
    {
        return [
            'sn' => $this->serialNumber,
            'is_test' => $this->isTest,
            'plu_data' => $pluData,
            'sign_structure' => $this->buildProductRegisterSignStructure($batch),
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
                return false;
            }

            return (bool) ($responseData['success'] ?? false);
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
    ): array {
        $summary = SalesVatCalculator::summarizeForLightStoresWorkflow($orderItems);
        $vat16NetTotal = $summary['vat16_net'];
        $vat16ValueTotal = $summary['vat16_value'];
        $vatExemptNetTotal = $summary['exempt_net'];

        $pluData = array_map(
            fn (array $item) => self::buildWorkflowPluLine($item),
            $orderItems,
        );

        $isCreditNote = $invoiceType === 'credit';

        $signStructure = [
            'SignType' => $this->isTest ? '0' : '1',
            'DiscAmt' => '0',
            'CashAmt' => number_format($totalAmount, 2, '.', ''),
            'CheckAmt' => '0',
            'CardAmt' => '0',
            'InvoiceType' => $invoiceType,
            'relevantInvoiceNumber' => $isCreditNote ? $relevantInvoiceNumber : '',
            'pinOfBuyer' => $buyerPin ?? '',
            'exemptionNumber' => '',
            'pinOfshop' => $this->pinNumber,
            'TraderSystemInvoiceNumber' => $invoiceNumber,
            'Vat A(Exempt) net' => number_format($vatExemptNetTotal, 2, '.', ''),
            'Vat A(Exempt) value' => '0',
            'Vat B(16.00%) net' => number_format($vat16NetTotal, 2, '.', ''),
            'Vat B(16.00%) value' => number_format($vat16ValueTotal, 2, '.', ''),
            'Vat C(0%) net' => '0',
            'Vat C(0%) value' => '0',
            'Vat D(Non-VAT) net' => '0',
            'Vat D(Non-VAT) value' => '0',
            'Vat E(8%) net' => '',
            'Vat E(8%) value' => '',
            'Schg F(10.00%) net' => '',
            'Schg F(10.00%) value' => '',
            'Levy G(2.00%) net' => '',
            'Levy G(2.00%) value' => '',
            'rfdRsnCd' => $isCreditNote ? $refundReasonCode : '',
            'NetTotal' => '',
            'EXCHANGERate' => '',
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
