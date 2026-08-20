<?php

namespace App\Services\Kra;

use App\Models\CreditNote;
use App\Models\Sale;
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
     * Products already on the device (E353 / THE SAME NAME) are skipped, not failed.
     *
     * @param  iterable<int, object>  $products
     */
    public function registerProducts(iterable $products, string $path = '/api/register-plu', array $financeSettings = []): array
    {
        $this->assertDeviceConfigured();

        $items = is_array($products) ? $products : iterator_to_array($products);
        if ($items === []) {
            return [
                'success' => false,
                'message' => 'No products to register.',
                'registered_count' => 0,
                'skipped_count' => 0,
                'product_count' => 0,
            ];
        }

        $path = $path !== '' ? $path : '/api/register-plu';
        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $registered = 0;
        $skipped = 0;
        $lastResponse = null;

        if ($this->usesUploadPluDataPayload($path)) {
            $batchSize = max(1, (int) (
                $financeSettings['kra_plu_upload_batch_size']
                ?? config('erp.module_settings_defaults.finance.kra_plu_upload_batch_size', 50)
            ));
            $chunks = array_chunk($items, $batchSize);

            foreach ($chunks as $index => $chunk) {
                $outcome = $this->uploadPluChunk($chunk, $path, $index + 1);
                if (! ($outcome['success'] ?? false)) {
                    return array_merge($outcome, [
                        'registered_count' => $registered,
                        'skipped_count' => $skipped,
                        'product_count' => count($items),
                        'batch' => $index + 1,
                        'product_code' => (string) ($chunk[0]->product_code ?? ''),
                    ]);
                }

                $registered += (int) ($outcome['registered_count'] ?? 0);
                $skipped += (int) ($outcome['skipped_count'] ?? 0);
                $lastResponse = $outcome['response'] ?? $lastResponse;
            }
        } else {
            $chunks = array_chunk($items, 200);

            foreach ($chunks as $index => $chunk) {
                $outcome = $this->registerPluChunk($chunk, $path, $index + 1);
                if (! ($outcome['success'] ?? false)) {
                    return array_merge($outcome, [
                        'registered_count' => $registered,
                        'skipped_count' => $skipped,
                        'product_count' => count($items),
                        'batch' => $index + 1,
                    ]);
                }

                $registered += (int) ($outcome['registered_count'] ?? 0);
                $skipped += (int) ($outcome['skipped_count'] ?? 0);
                $lastResponse = $outcome['response'] ?? $lastResponse;
            }
        }

        return [
            'success' => true,
            'message' => $this->registrationSummaryMessage($registered, $skipped),
            'registered_count' => $registered,
            'skipped_count' => $skipped,
            'product_count' => count($items),
            'response' => $lastResponse,
        ];
    }

    /**
     * @param  list<object>  $chunk
     * @return array{success: bool, registered_count?: int, skipped_count?: int, message?: string, response?: mixed}
     */
    protected function uploadPluChunk(array $chunk, string $path, int $batch): array
    {
        $pluItems = array_map(
            fn ($product) => self::buildComstorePluItemFromProduct($product),
            $chunk,
        );
        $payload = $this->buildComstoreUploadPayload($pluItems);
        $result = $this->postToDevice($path, $payload, [
            'batch' => $batch,
            'plu_count' => count($pluItems),
        ]);

        if ($result['success'] ?? false) {
            return [
                'success' => true,
                'registered_count' => count($chunk),
                'skipped_count' => 0,
                'response' => $result['response'] ?? null,
            ];
        }

        if (! $this->isAlreadyRegisteredPluResult($result)) {
            return $result;
        }

        // Batch failed because at least one name already exists — retry one-by-one and skip duplicates.
        if (count($chunk) === 1) {
            return [
                'success' => true,
                'registered_count' => 0,
                'skipped_count' => 1,
                'response' => $result['response'] ?? null,
            ];
        }

        $registered = 0;
        $skipped = 0;
        $lastResponse = $result['response'] ?? null;

        foreach ($chunk as $product) {
            $one = $this->uploadPluChunk([$product], $path, $batch);
            if (! ($one['success'] ?? false)) {
                return $one;
            }
            $registered += (int) ($one['registered_count'] ?? 0);
            $skipped += (int) ($one['skipped_count'] ?? 0);
            $lastResponse = $one['response'] ?? $lastResponse;
        }

        return [
            'success' => true,
            'registered_count' => $registered,
            'skipped_count' => $skipped,
            'response' => $lastResponse,
        ];
    }

    /**
     * @param  list<object>  $chunk
     * @return array{success: bool, registered_count?: int, skipped_count?: int, message?: string, response?: mixed}
     */
    protected function registerPluChunk(array $chunk, string $path, int $batch): array
    {
        $pluData = array_map(fn ($product) => self::buildPluLineFromProduct($product), $chunk);
        $payload = $this->buildProductRegisterPayload($pluData, $batch, $path);
        $result = $this->postToDevice($path, $payload, [
            'batch' => $batch,
            'plu_count' => count($pluData),
        ]);

        if ($result['success'] ?? false) {
            return [
                'success' => true,
                'registered_count' => count($chunk),
                'skipped_count' => 0,
                'response' => $result['response'] ?? null,
            ];
        }

        if (! $this->isAlreadyRegisteredPluResult($result)) {
            return $result;
        }

        if (count($chunk) === 1) {
            return [
                'success' => true,
                'registered_count' => 0,
                'skipped_count' => 1,
                'response' => $result['response'] ?? null,
            ];
        }

        $registered = 0;
        $skipped = 0;
        $lastResponse = $result['response'] ?? null;

        foreach ($chunk as $product) {
            $one = $this->registerPluChunk([$product], $path, $batch);
            if (! ($one['success'] ?? false)) {
                return $one;
            }
            $registered += (int) ($one['registered_count'] ?? 0);
            $skipped += (int) ($one['skipped_count'] ?? 0);
            $lastResponse = $one['response'] ?? $lastResponse;
        }

        return [
            'success' => true,
            'registered_count' => $registered,
            'skipped_count' => $skipped,
            'response' => $lastResponse,
        ];
    }

    /** @param  array<string, mixed>  $result */
    public function isAlreadyRegisteredPluResult(array $result): bool
    {
        if (($result['error_code'] ?? null) === '353') {
            return true;
        }

        $raw = $result['technical_message']
            ?? $result['message']
            ?? $result['response']
            ?? '';
        $translated = KraDeviceErrorTranslator::translate($raw);

        if (($translated['code'] ?? null) === '353') {
            return true;
        }

        $haystack = strtoupper(
            trim((string) ($translated['technical_message'] ?? ''))
            .' '
            .trim((string) ($result['message'] ?? ''))
            .' '
            .trim(is_string($raw) ? $raw : (json_encode($raw) ?: ''))
        );

        return str_contains($haystack, 'THE SAME NAME')
            || str_contains($haystack, 'SAME NAME')
            || str_contains($haystack, 'ALREADY REGISTERED');
    }

    protected function registrationSummaryMessage(int $registered, int $skipped): string
    {
        if ($registered === 0 && $skipped > 0) {
            return $skipped === 1
                ? '1 product was already on the KRA device (skipped).'
                : "{$skipped} products were already on the KRA device (skipped).";
        }

        if ($skipped > 0) {
            return "Registered {$registered} product(s) on KRA device; {$skipped} already on device (skipped).";
        }

        return "Registered {$registered} product(s) on KRA device.";
    }

    public function generateInvoiceNumber(): string
    {
        return (string) max(1, (int) substr((string) time(), -9) + random_int(1, 99));
    }

    public function traderInvoiceForSale(Sale $sale, array $financeSettings = []): string
    {
        return app(KraTraderInvoiceAllocator::class)->forSale($sale, $financeSettings);
    }

    public function traderInvoiceForCreditNote(CreditNote $creditNote, array $financeSettings = []): string
    {
        return app(KraTraderInvoiceAllocator::class)->forCreditNote($creditNote, $financeSettings);
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
        // Prefer product id so each PLU is unique — defaults.plu_no is only a last-resort fallback.
        $pluNo = trim((string) ($product->id ?? ''));
        if ($pluNo === '' || $pluNo === '0') {
            $pluNo = $productCode !== '' ? $productCode : (string) ($defaults['plu_no'] ?? '1');
        }
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

    /** @param  array<int, array<string, mixed>>  $pluItems */
    protected function buildComstoreUploadPayload(array $pluItems): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('erp.module_settings_defaults.finance.kra_plu_defaults', []);

        return [
            'sn' => $this->serialNumber,
            'plu_items' => array_values($pluItems),
            'from_no' => (int) ($defaults['from_no'] ?? 1),
            'end_no' => (int) ($defaults['end_no'] ?? 100000),
            'update_flag' => (int) ($defaults['update_flag'] ?? 0),
            'file_signal' => '',
        ];
    }

    /**
     * Resolve Smart VSCU hardware IP for /api/init and /api/restart-device.
     *
     * @param  array<string, mixed>  $financeSettings
     */
    public static function resolveHardwareIp(array $financeSettings): string
    {
        $explicit = trim((string) ($financeSettings['kra_device_hardware_ip'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $apiUrl = trim((string) ($financeSettings['kra_device_ip'] ?? ''));
        if ($apiUrl === '') {
            return '';
        }

        if (! str_starts_with($apiUrl, 'http://') && ! str_starts_with($apiUrl, 'https://')) {
            $apiUrl = 'http://'.$apiUrl;
        }

        $host = parse_url($apiUrl, PHP_URL_HOST);

        return is_string($host) && filter_var($host, FILTER_VALIDATE_IP) ? $host : '';
    }

    /** @return array<string, string> */
    public static function buildWorkflowPluLine(array $item): array
    {
        $amount = round(max(0.0, (float) ($item['amount'] ?? 0)), 2);
        $quantity = max(0.001, (float) ($item['quantity'] ?? 1));
        $itemName = trim((string) ($item['product_name'] ?? 'Product'));
        $productCode = trim((string) ($item['product_code'] ?? ''));

        return [
            'item_Name' => $itemName !== '' ? $itemName : 'Product',
            // Comstore sale workflow expects an empty Barcode; keep product_code for
            // Centrix matching against device SKU / "NO FIND PLU DATA for item …".
            'Barcode' => '',
            'product_code' => $productCode,
            'SalePrice' => self::formatWorkflowSalePrice($amount, $quantity),
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

    /**
     * Unit price for KRA complete-workflow PLU lines.
     *
     * The fiscal device validates SalePrice × SaleQty = SaleAmount. With fractional
     * quantities (e.g. 12.5 kg), a 2-decimal price derived from a rounded line total
     * often fails (33.33 × 12.5 = 416.625 ≠ 416.63).
     */
    protected static function formatWorkflowSalePrice(float $amount, float $quantity): string
    {
        if ($amount <= 0 || $quantity <= 0) {
            return '0.00';
        }

        foreach ([2, 4] as $decimals) {
            $unitPrice = round($amount / $quantity, $decimals);
            if (abs(($unitPrice * $quantity) - $amount) < 0.0001) {
                return number_format($unitPrice, $decimals, '.', '');
            }
        }

        return number_format($amount / $quantity, 4, '.', '');
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

        $normalizedPath = strtolower($path);
        if (str_contains($normalizedPath, '/api/init') || str_contains($normalizedPath, 'restart-device')) {
            if (! is_array($responseData)) {
                return false;
            }

            if (array_key_exists('success', $responseData)) {
                return (bool) $responseData['success'];
            }

            return strtoupper((string) ($responseData['status'] ?? '')) === 'OK';
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
        $normalizedItems = array_map(function (array $item): array {
            $amount = round(max(0.0, (float) ($item['amount'] ?? 0)), 2);
            $productVat = (float) ($item['product_vat'] ?? 0);
            if ($productVat > 0.0001 && $amount > 0) {
                $productVat = round($amount - ($amount / 1.16), 2);
            } else {
                $productVat = 0.0;
            }

            return array_merge($item, [
                'amount' => $amount,
                'product_vat' => $productVat,
            ]);
        }, $orderItems);

        $summary = SalesVatCalculator::summarizeForLightStoresWorkflow($normalizedItems);
        $vat16NetTotal = $summary['vat16_net'];
        $vat16ValueTotal = $summary['vat16_value'];
        $vatExemptNetTotal = $summary['exempt_net'];

        $pluData = array_map(
            fn (array $item) => self::buildWorkflowPluLine($item),
            $normalizedItems,
        );

        $pluLineTotal = 0.0;
        foreach ($pluData as $line) {
            $pluLineTotal += (float) ($line['SaleAmount'] ?? 0);
        }
        $pluLineTotal = round($pluLineTotal, 2);
        $cashAmt = round($totalAmount, 2);
        // KRA validates sum(plu SaleAmount) against CashAmt — prefer line sum when they diverge.
        if (abs($pluLineTotal - $cashAmt) > 0.009) {
            $cashAmt = $pluLineTotal;
        }

        $isCreditNote = $invoiceType === 'credit';

        $signStructure = [
            'SignType' => $this->isTest ? '0' : '1',
            'DiscAmt' => '0',
            'CashAmt' => number_format($cashAmt, 2, '.', ''),
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

    /** Probe the on-prem Comstore device health endpoint (GET /api/health). */
    public function checkHealth(): array
    {
        $url = $this->deviceBaseUrl.'/api/health';

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get($url);

            $body = $response->json();
            $isArray = is_array($body);
            $httpOk = $response->successful();
            $statusOk = ! $isArray || ! isset($body['status'])
                || strtoupper((string) $body['status']) === 'OK';
            $deviceConnected = ! $isArray || ! isset($body['deviceConnection'])
                || strcasecmp((string) $body['deviceConnection'], 'Connected') === 0;
            $successful = $httpOk && $statusOk && $deviceConnected;

            $message = $this->healthMessage($body, $httpOk, $statusOk, $deviceConnected);

            return [
                'success' => $successful,
                'reachable' => true,
                'http_status' => $response->status(),
                'url' => $url,
                'message' => $message,
                'device_connection' => $isArray ? ($body['deviceConnection'] ?? null) : null,
                'api_service' => $isArray ? ($body['apiService'] ?? null) : null,
                'device_version' => $isArray ? ($body['version'] ?? null) : null,
                'response' => $isArray ? $body : ['body' => $response->body()],
            ];
        } catch (\Throwable $e) {
            Log::warning('KRA device health check failed: '.$e->getMessage(), ['url' => $url]);

            return [
                'success' => false,
                'reachable' => false,
                'http_status' => null,
                'url' => $url,
                'message' => KraDeviceErrorTranslator::userMessage('Could not reach KRA device: '.$e->getMessage()),
                'device_connection' => null,
                'api_service' => null,
                'device_version' => null,
                'response' => null,
            ];
        }
    }

    /** Initialize Smart VSCU connection (POST /api/init). */
    public function initializeDevice(string $hardwareIp): array
    {
        if ($this->serialNumber === '') {
            throw new InvalidArgumentException('KRA device serial number is not configured.');
        }

        $hardwareIp = trim($hardwareIp);
        if ($hardwareIp === '') {
            throw new InvalidArgumentException('Fiscal device hardware IP is required for initialization.');
        }

        return $this->postToDevice('/api/init', [
            'sn' => $this->serialNumber,
            'ip' => $hardwareIp,
        ], [
            'operation' => 'init',
            'hardware_ip' => $hardwareIp,
        ]);
    }

    /** Restart Smart VSCU hardware (POST /api/restart-device). */
    public function restartDevice(string $hardwareIp): array
    {
        $hardwareIp = trim($hardwareIp);
        if ($hardwareIp === '') {
            throw new InvalidArgumentException('Fiscal device hardware IP is required to restart the device.');
        }

        return $this->postToDevice('/api/restart-device', [
            'ip_address' => $hardwareIp,
        ], [
            'operation' => 'restart',
            'hardware_ip' => $hardwareIp,
        ]);
    }

    /** @param  array<string, mixed>|null  $body */
    protected function healthMessage(mixed $body, bool $httpOk, bool $statusOk, bool $deviceConnected): string
    {
        if (! $httpOk) {
            return 'KRA device health check failed (HTTP error).';
        }

        if (! is_array($body)) {
            return 'KRA device health check passed.';
        }

        if (! $statusOk) {
            return 'Comstore API reported a problem: '.((string) ($body['status'] ?? 'Unknown status'));
        }

        if (! $deviceConnected) {
            return 'Comstore API is running but the fiscal device is disconnected. Check power and network to the Smart VSCU.';
        }

        $version = trim((string) ($body['version'] ?? ''));
        $apiService = trim((string) ($body['apiService'] ?? ''));

        $parts = ['KRA device health check passed'];
        if ($version !== '') {
            $parts[] = "v{$version}";
        }
        if ($apiService !== '') {
            $parts[] = strtolower($apiService);
        }

        return implode(' · ', $parts).'.';
    }

    /** @param  array<string, mixed>  $context */
    protected function postToDevice(string $path, array $payload, array $context = []): array
    {
        $url = $this->deviceBaseUrl . $path;

        try {
            $response = Http::timeout(60)
                ->retry(2, 200, throw: false)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);

            $responseData = $response->json();
            $successful = $this->deviceResponseSuccessful(
                $response,
                is_array($responseData) ? $responseData : null,
                $path,
            );

            if (! $response->successful() && is_array($responseData) && ! empty($responseData['message'])) {
                return $this->deviceFailureResult(
                    (string) $responseData['message'],
                    $payload,
                    $this->mapResponse($responseData),
                );
            }

            $rawMessage = is_array($responseData)
                ? ($responseData['message'] ?? $responseData['Message'] ?? $response->body())
                : $response->body();

            if (! $successful) {
                return $this->deviceFailureResult((string) $rawMessage, $payload, $this->mapResponse(is_array($responseData) ? $responseData : null));
            }

            return [
                'success' => true,
                'message' => $rawMessage,
                'payload' => $payload,
                'response' => $this->mapResponse(is_array($responseData) ? $responseData : null),
            ];
        } catch (\Throwable $e) {
            Log::error('KRA device API error: ' . $e->getMessage(), array_merge([
                'url' => $url,
            ], $context));

            return $this->deviceFailureResult('Exception: ' . $e->getMessage(), $payload);
        }
    }

    /** @return array<string, mixed> */
    protected function deviceFailureResult(string $rawMessage, array $payload, ?array $response = null): array
    {
        $translated = KraDeviceErrorTranslator::translate($rawMessage);
        $message = (string) ($translated['message'] ?? '');
        $technical = (string) ($translated['technical_message'] ?? $rawMessage);
        $code = $translated['code'] ?? null;

        // Prefer the exact PLU / SKU token from the device when present.
        // Do NOT list every sale line as missing — that made the UI mark every
        // product as the cause even when only one device SKU failed.
        if (preg_match('/NO\s+FIND\s+PLU\s+DATA\s+for\s+item\s+(.+?)(?:\s+error|\s*$|[.;,]|\s+Upload)/is', $technical, $match) === 1
            || preg_match('/NO\s+FIND\s+PLU\s+DATA\s+for\s+item\s+([^\s,;]+)/i', $technical, $match) === 1
        ) {
            $item = trim((string) ($match[1] ?? ''));
            $item = rtrim($item, " \t\n\r\0\x0B.;,");
            if ($item !== '') {
                $label = $this->resolvePluLabelForDeviceToken($payload, $item) ?? $item;
                $message = 'Product not found on the KRA device: '.$label.'. Upload it to the device first, then retry.';
            }
        } elseif (
            in_array((string) $code, ['337', '13'], true)
            || preg_match('/NO\s+FIND\s+PLU\s+DATA|not found on the KRA device/i', $message.$technical) === 1
        ) {
            $labels = $this->pluLabelsFromPayload($payload);
            // Only name the product when the sale has a single line — otherwise keep
            // the generic translator message (device did not identify which PLU).
            if (count($labels) === 1) {
                $message = 'Product not found on the KRA device: '.$labels[0].'. Upload it to the device first, then retry.';
            }
        }

        return [
            'success' => false,
            'message' => $message,
            'technical_message' => $technical,
            'error_code' => $code,
            'payload' => $payload,
            'response' => $response,
        ];
    }

    /**
     * Map a device "for item …" token to the sale line label using product_code / SKU.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function resolvePluLabelForDeviceToken(array $payload, string $token): ?string
    {
        $tokenNorm = strtolower(trim($token));
        if ($tokenNorm === '') {
            return null;
        }

        $prefix = strtolower((string) (config('erp.module_settings_defaults.finance.kra_plu_defaults.barcode_prefix') ?? '000000'));
        $raw = $payload['plu_data'] ?? $payload['PluData'] ?? [];
        if (! is_array($raw)) {
            return null;
        }

        foreach ($raw as $line) {
            if (! is_array($line)) {
                continue;
            }
            $name = trim((string) ($line['item_Name'] ?? $line['ItemName'] ?? $line['product_name'] ?? ''));
            $code = trim((string) ($line['product_code'] ?? $line['Barcode'] ?? $line['barcode'] ?? $line['itemCd'] ?? ''));
            $codeNorm = strtolower($code);
            $prefixed = $codeNorm !== '' ? $prefix.$codeNorm : '';
            $nameNorm = strtolower($name);

            $matches =
                ($codeNorm !== '' && ($codeNorm === $tokenNorm || $prefixed === $tokenNorm
                    || str_ends_with($tokenNorm, $codeNorm)))
                || ($nameNorm !== '' && ($nameNorm === $tokenNorm || $nameNorm === str_replace('_', ' ', $tokenNorm)));

            if (! $matches) {
                continue;
            }

            return $code !== '' ? ($name !== '' ? "{$name} ({$code})" : $code) : $name;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function pluLabelsFromPayload(array $payload): array
    {
        $raw = $payload['plu_data'] ?? $payload['PluData'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $labels = [];
        foreach ($raw as $line) {
            if (! is_array($line)) {
                continue;
            }
            $name = trim((string) ($line['item_Name'] ?? $line['ItemName'] ?? $line['product_name'] ?? ''));
            $code = trim((string) ($line['product_code'] ?? $line['Barcode'] ?? $line['barcode'] ?? $line['itemCd'] ?? ''));
            if ($name === '' && $code === '') {
                continue;
            }
            $labels[] = $code !== '' ? ($name !== '' ? "{$name} ({$code})" : $code) : $name;
        }

        return array_values(array_unique($labels));
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
            'receipt_signature' => $responseData['Receipt Signature']
                ?? $responseData['receipt_signature']
                ?? $responseData['signature']
                ?? null,
            'signature_link' => $responseData['signature_link']
                ?? $responseData['Signature Link']
                ?? $responseData['signatureLink']
                ?? $responseData['qr_link']
                ?? $responseData['qr_url']
                ?? $responseData['verification_url']
                ?? null,
            'version' => $responseData['version'] ?? null,
        ];
    }
}
