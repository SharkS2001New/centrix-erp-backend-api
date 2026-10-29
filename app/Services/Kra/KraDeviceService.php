<?php

namespace App\Services\Kra;

use App\Models\CreditNote;
use App\Models\Sale;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class KraDeviceService
{
    protected ?KraAgentBridge $agentBridge = null;

    protected ?\App\Models\KraAgent $kraAgent = null;

    protected string $hardwareIp = '';

    protected string $comstoreApiKey = '';

    public function __construct(
        protected string $deviceBaseUrl,
        protected string $serialNumber,
        protected string $pinNumber,
        protected bool $isTest = false,
    ) {}

    public static function fromSettings(array $financeSettings, ?int $organizationId = null): self
    {
        $base = trim((string) ($financeSettings['kra_device_ip'] ?? ''));
        if ($base === '') {
            // Centrix KRA Agent defaults to local Comstore when not set yet.
            $base = 'http://localhost:4000';
        }

        if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
            $base = 'http://' . $base;
        }

        $base = rtrim($base, '/');
        // localhost and 127.0.0.1 are both valid for on-prem Comstore / agent.
        $base = app(KraAgentBridge::class)->normalizeComstoreUrl($base);

        $service = new self(
            $base,
            trim((string) ($financeSettings['kra_serial_number'] ?? '')),
            trim((string) ($financeSettings['kra_pin_number'] ?? '')),
            (bool) ($financeSettings['kra_device_test_mode'] ?? config('app.env') !== 'production'),
        );
        $service->hardwareIp = trim((string) ($financeSettings['kra_device_hardware_ip'] ?? ''));
        $service->comstoreApiKey = trim((string) ($financeSettings['kra_comstore_api_key'] ?? ''));

        $orgId = $organizationId
            ?? (int) ($financeSettings['_organization_id'] ?? 0);
        // Centrix KRA Agent is the only fiscal path (POS, mobile, backoffice).
        if (! empty($financeSettings['enable_kra_device']) && $orgId > 0) {
            $bridge = app(KraAgentBridge::class);
            $agent = $bridge->resolveOrCreateForOrganization($orgId, $financeSettings);
            $service->useAgentBridge($bridge, $agent);
        }

        return $service;
    }

    public function useAgentBridge(KraAgentBridge $bridge, \App\Models\KraAgent $agent): self
    {
        $this->agentBridge = $bridge;
        $this->kraAgent = $agent;

        return $this;
    }

    public function usesAgentBridge(): bool
    {
        return $this->agentBridge !== null && $this->kraAgent !== null;
    }

    /** Agent checked in recently — safe to skip the extra health hop on checkout. */
    public function agentIsWarm(): bool
    {
        if (! $this->usesAgentBridge()) {
            return false;
        }

        return $this->agentBridge->isAgentOnline($this->kraAgent)
            || $this->agentBridge->hasRecentCheckIn($this->kraAgent);
    }

    /**
     * Last agent heartbeat about Comstore / fiscal hardware.
     *
     * @return array{ready: bool|null, message: ?string}
     *   ready=true  → fiscal path looked healthy on last heartbeat (skip extra health)
     *   ready=false → Comstore/device known down — soft-skip without waiting
     *   ready=null  → unknown — run a short health probe
     */
    public function agentFiscalPreflight(): array
    {
        // Centrix KRA Agent is required — cloud never talks to Comstore directly.
        if (! $this->usesAgentBridge() || $this->kraAgent === null) {
            return [
                'ready' => false,
                'message' => 'Centrix KRA Agent is required for fiscalization. Install CentrixKraAgent where Comstore runs, then try again.',
            ];
        }

        if (! $this->agentIsWarm()) {
            return ['ready' => null, 'message' => null];
        }

        $agent = $this->kraAgent;
        if ($agent->comstore_reachable === false) {
            // Under continuous sales, a concurrent heartbeat probe can falsely flip Comstore
            // down while the agent is mid complete-workflow. Keep fiscalizing if work is active.
            if ($this->agentBridge->hasActiveCommands($agent)) {
                return ['ready' => true, 'message' => null];
            }

            $message = trim((string) ($agent->comstore_status_message ?? ''));
            if ($message === '' || KraAgentBridge::isComstoreManualStartRequired($message)) {
                $message = KraAgentBridge::comstoreManualStartUserMessage(
                    (string) ($agent->comstore_base_url ?: $this->deviceBaseUrl),
                );
            }

            return ['ready' => false, 'message' => $message];
        }

        // Never soft-skip solely on device ICMP — fiscal hardware often looks unreachable
        // while it is busy signing the previous receipt. Still attempt complete-workflow.
        if ($agent->comstore_reachable === true) {
            return ['ready' => true, 'message' => null];
        }

        return ['ready' => null, 'message' => null];
    }

    public function sendSale(
        array $orderItems,
        float $totalAmount,
        string $invoiceNumber,
        ?string $buyerPin = null,
        array $context = [],
    ): array {
        $this->assertDeviceConfigured();
        $tenders = $context['tenders'] ?? null;
        unset($context['tenders']);

        $payload = $this->buildWorkflowPayload(
            $orderItems,
            $totalAmount,
            $invoiceNumber,
            'original',
            '',
            '',
            $buyerPin,
            is_array($tenders) ? $tenders : null,
        );

        $result = $this->postToDevice('/api/complete-workflow', $payload, array_merge([
            'invoice' => $invoiceNumber,
            'document_type' => 'sale',
        ], $context));

        return $this->queueOnlineTransmission($result, $context);
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
        array $context = [],
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
            $this->refundTenders($refundMethod, $totalAmount),
        );

        $result = $this->postToDevice('/api/complete-workflow', $payload, array_merge([
            'invoice' => $invoiceNumber,
            'document_type' => 'credit_note',
            'relevant_invoice' => $relevantInvoiceNumber,
        ], $context));

        return $this->queueOnlineTransmission($result, $context);
    }

    /** Queue Comstore's documented upload of signed invoices to eTIMS. */
    public function uploadPendingInvoices(array $context = []): array
    {
        $this->assertDeviceConfigured();

        return $this->postToDevice(
            '/api/invoices/manually-upload/'.rawurlencode($this->serialNumber),
            null,
            $context,
        );
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $context @return array<string, mixed> */
    protected function queueOnlineTransmission(array $result, array $context): array
    {
        if (! ($result['success'] ?? false) || $this->isTest) {
            return $result;
        }

        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $deviceStatus = strtolower(trim((string) ($response['transmit_status'] ?? '')));
        if ($deviceStatus !== ''
            && str_contains($deviceStatus, 'transmitted to etims')
            && ! str_contains($deviceStatus, 'not yet')) {
            $response['online_upload_status'] = 'transmitted';
            $result['response'] = $response;

            return $result;
        }

        $uploadContext = $context;
        unset($uploadContext['tenders']);
        $uploadContext['agent_wait_seconds'] = min(
            8,
            max(2, (int) ($uploadContext['agent_wait_seconds'] ?? KraAgentBridge::COMMAND_WAIT_SECONDS)),
        );
        $upload = $this->uploadPendingInvoices($uploadContext);
        $response['online_upload_status'] = ($upload['success'] ?? false) ? 'queued' : 'pending';
        $response['online_upload_response'] = $upload['response'] ?? null;
        $response['online_upload_error'] = ($upload['success'] ?? false)
            ? null
            : (string) ($upload['message'] ?? 'Could not queue invoice for eTIMS upload.');
        if ($response['online_upload_status'] === 'queued') {
            $response['transmit_status'] = 'Invoices queued for manual upload to eTIMS.';
        }
        $result['response'] = $response;

        return $result;
    }

    /** @return array{cash: float, check: float, card: float} */
    protected function refundTenders(?string $refundMethod, float $amount): array
    {
        $method = strtoupper(trim((string) $refundMethod));

        return match (true) {
            in_array($method, ['CHEQUE', 'CHECK'], true) => ['cash' => 0.0, 'check' => $amount, 'card' => 0.0],
            in_array($method, ['CARD', 'MPESA', 'MOBILE', 'BANK', 'EQUITY', 'KCB', 'AIRTEL'], true) => ['cash' => 0.0, 'check' => 0.0, 'card' => $amount],
            default => ['cash' => $amount, 'check' => 0.0, 'card' => 0.0],
        };
    }

    /**
     * Register catalogue products on the on-prem KRA device (LightStores PLU upload).
     * A product the device rejects is skipped. The rest of the catalogue still uploads.
     * A device or connection failure stops the upload, because every item would fail the same way.
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
        $skippedProducts = [];
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
                        'registered_count' => $registered + (int) ($outcome['registered_count'] ?? 0),
                        'skipped_count' => $skipped + (int) ($outcome['skipped_count'] ?? 0),
                        'skipped_products' => array_merge($skippedProducts, $outcome['skipped_products'] ?? []),
                        'product_count' => count($items),
                        'batch' => $index + 1,
                    ]);
                }

                $registered += (int) ($outcome['registered_count'] ?? 0);
                $skipped += (int) ($outcome['skipped_count'] ?? 0);
                $skippedProducts = array_merge($skippedProducts, $outcome['skipped_products'] ?? []);
                $lastResponse = $outcome['response'] ?? $lastResponse;
            }
        } else {
            $chunks = array_chunk($items, 200);

            foreach ($chunks as $index => $chunk) {
                $outcome = $this->registerPluChunk($chunk, $path, $index + 1);
                if (! ($outcome['success'] ?? false)) {
                    return array_merge($outcome, [
                        'registered_count' => $registered + (int) ($outcome['registered_count'] ?? 0),
                        'skipped_count' => $skipped + (int) ($outcome['skipped_count'] ?? 0),
                        'skipped_products' => array_merge($skippedProducts, $outcome['skipped_products'] ?? []),
                        'product_count' => count($items),
                        'batch' => $index + 1,
                    ]);
                }

                $registered += (int) ($outcome['registered_count'] ?? 0);
                $skipped += (int) ($outcome['skipped_count'] ?? 0);
                $skippedProducts = array_merge($skippedProducts, $outcome['skipped_products'] ?? []);
                $lastResponse = $outcome['response'] ?? $lastResponse;
            }
        }

        return [
            'success' => true,
            'message' => $this->registrationSummaryMessage($registered, $skipped, $skippedProducts),
            'registered_count' => $registered,
            'skipped_count' => $skipped,
            'skipped_products' => $skippedProducts,
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

        if ($this->isDeviceLevelPluFailure($result)) {
            return count($chunk) === 1
                ? $this->annotatePluFailureWithProduct($result, $chunk[0])
                : $result;
        }

        if ($this->isAlreadyRegisteredPluResult($result)) {
            if (count($chunk) === 1) {
                return $this->skippedPluProduct($chunk[0], $result, 'already_registered');
            }

            return $this->retryPluChunkIndividually($chunk, $path, $batch, 'upload');
        }

        if (count($chunk) > 1) {
            return $this->retryPluChunkIndividually($chunk, $path, $batch, 'upload');
        }

        return $this->skippedPluProduct($chunk[0], $result, 'rejected');
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

        if ($this->isDeviceLevelPluFailure($result)) {
            return count($chunk) === 1
                ? $this->annotatePluFailureWithProduct($result, $chunk[0])
                : $result;
        }

        if ($this->isAlreadyRegisteredPluResult($result)) {
            if (count($chunk) === 1) {
                return $this->skippedPluProduct($chunk[0], $result, 'already_registered');
            }

            return $this->retryPluChunkIndividually($chunk, $path, $batch, 'register');
        }

        if (count($chunk) > 1) {
            return $this->retryPluChunkIndividually($chunk, $path, $batch, 'register');
        }

        return $this->skippedPluProduct($chunk[0], $result, 'rejected');
    }

    /**
     * Retry each product alone. A rejected item is skipped; a device failure stops the batch.
     *
     * @param  list<object>  $chunk
     * @param  'upload'|'register'  $mode
     * @return array{success: bool, registered_count?: int, skipped_count?: int, message?: string, response?: mixed}
     */
    protected function retryPluChunkIndividually(array $chunk, string $path, int $batch, string $mode): array
    {
        $registered = 0;
        $skipped = 0;
        $skippedProducts = [];
        $lastResponse = null;

        foreach ($chunk as $product) {
            $one = $mode === 'upload'
                ? $this->uploadPluChunk([$product], $path, $batch)
                : $this->registerPluChunk([$product], $path, $batch);
            if (! ($one['success'] ?? false)) {
                return array_merge($one, [
                    'registered_count' => $registered + (int) ($one['registered_count'] ?? 0),
                    'skipped_count' => $skipped + (int) ($one['skipped_count'] ?? 0),
                    'skipped_products' => array_merge($skippedProducts, $one['skipped_products'] ?? []),
                ]);
            }
            $registered += (int) ($one['registered_count'] ?? 0);
            $skipped += (int) ($one['skipped_count'] ?? 0);
            $skippedProducts = array_merge($skippedProducts, $one['skipped_products'] ?? []);
            $lastResponse = $one['response'] ?? $lastResponse;
        }

        return [
            'success' => true,
            'registered_count' => $registered,
            'skipped_count' => $skipped,
            'skipped_products' => $skippedProducts,
            'response' => $lastResponse,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  'already_registered'|'rejected'  $reason
     * @return array{success: bool, registered_count: int, skipped_count: int, skipped_products: list<array<string, string>>, response: mixed}
     */
    protected function skippedPluProduct(object $product, array $result, string $reason): array
    {
        $name = trim((string) ($product->product_name ?? ''));
        $code = trim((string) ($product->product_code ?? ''));
        $message = trim((string) ($result['message'] ?? ''));
        $message = trim(preg_replace('/\.?\s*Rejected product:\s*.+$/i', '', $message) ?? $message);

        return [
            'success' => true,
            'registered_count' => 0,
            'skipped_count' => 1,
            'skipped_products' => [[
                'product_code' => $code,
                'product_name' => $name,
                'reason' => $reason,
                'message' => $message,
            ]],
            'response' => $result['response'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function annotatePluFailureWithProduct(array $result, object $product): array
    {
        $name = trim((string) ($product->product_name ?? ''));
        $code = trim((string) ($product->product_code ?? ''));
        $label = $code !== ''
            ? ($name !== '' ? "{$name} ({$code})" : $code)
            : ($name !== '' ? $name : 'unknown product');

        $message = trim((string) ($result['message'] ?? 'KRA product registration failed.'));
        // Drop any prior "Rejected product: …" so barcode vs product_code never doubles up.
        $message = trim(preg_replace('/\.?\s*Rejected product:\s*.+$/i', '', $message) ?? $message);
        if ($label !== '') {
            $result['message'] = rtrim($message, '.').". Rejected product: {$label}.";
        }
        $result['product_code'] = $code;
        $result['product_name'] = $name;

        return $result;
    }

    /**
     * The device or the link to it failed. Retrying the next product would fail the same way.
     *
     * @param  array<string, mixed>  $result
     */
    public static function isDeviceLevelPluFailure(array $result): bool
    {
        $code = (string) ($result['error_code'] ?? '');
        if (in_array($code, ['901', '902', '903', '96', '90', '519', '518', '520', '40', '99'], true)) {
            return true;
        }

        $raw = $result['technical_message'] ?? $result['message'] ?? $result['response'] ?? '';
        $haystack = strtolower(trim((string) ($result['message'] ?? '')).' '.trim(is_string($raw) ? $raw : (json_encode($raw) ?: '')));

        foreach ([
            'device not initialized',
            'not initialized',
            'could not reach',
            'connection refused',
            'timed out',
            'did not respond',
            'not communicating',
            'disconnected',
            'kra agent',
            'comstore',
            'has not checked in',
            'has never checked in',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $result */
    public static function isInvalidPluUploadResult(array $result): bool
    {
        $code = (string) ($result['error_code'] ?? '');
        if ($code === '034') {
            return true;
        }

        $raw = $result['technical_message']
            ?? $result['message']
            ?? $result['response']
            ?? '';
        $haystack = trim((string) ($result['message'] ?? ''))
            .' '
            .trim(is_string($raw) ? $raw : (json_encode($raw) ?: ''));
        $upper = strtoupper($haystack);

        if ($code === '34' && KraDeviceErrorTranslator::looksLikePluUploadFailure($haystack)) {
            return true;
        }

        return str_contains($upper, 'SETPLUDATAINFOEX')
            || str_contains($upper, 'ERROR PROCESSING PLU')
            || str_contains($upper, 'PRODUCT UPLOAD DATA IS INVALID');
    }

    /** @param  array<string, mixed>  $result */
    public static function isAlreadyRegisteredPluResult(array $result): bool
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
            || str_contains($haystack, 'ALREADY REGISTERED')
            || str_contains($haystack, 'ALREADY ON THE KRA DEVICE');
    }

    /**
     * @param  list<array<string, string>>  $skippedProducts
     */
    protected function registrationSummaryMessage(int $registered, int $skipped, array $skippedProducts = []): string
    {
        $rejected = array_values(array_filter(
            $skippedProducts,
            fn (array $row) => ($row['reason'] ?? '') !== 'already_registered',
        ));
        $already = max(0, $skipped - count($rejected));

        if ($registered === 0 && $rejected === [] && $skipped > 0) {
            return $skipped === 1
                ? '1 product was already on the KRA device (skipped).'
                : "{$skipped} products were already on the KRA device (skipped).";
        }

        $parts = [];
        if ($registered > 0 || $rejected === []) {
            $parts[] = "Registered {$registered} product(s) on KRA device.";
        }
        if ($already > 0) {
            $parts[] = $already === 1
                ? '1 already on device (skipped).'
                : "{$already} already on device (skipped).";
        }
        if ($rejected !== []) {
            $labels = [];
            foreach (array_slice($rejected, 0, 8) as $row) {
                $name = trim((string) ($row['product_name'] ?? ''));
                $code = trim((string) ($row['product_code'] ?? ''));
                $label = $code !== ''
                    ? ($name !== '' ? "{$name} ({$code})" : $code)
                    : ($name !== '' ? $name : 'a product');
                $reason = trim((string) ($row['message'] ?? ''));
                $labels[] = $reason !== '' ? "{$label} — {$reason}" : $label;
            }
            $more = count($rejected) - count($labels);
            $list = implode('; ', $labels);
            if ($more > 0) {
                $list .= "; and {$more} more";
            }
            $parts[] = 'Skipped '.count($rejected).' with errors: '.$list.'.';
        }

        return implode(' ', $parts);
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
        $safeCode = preg_replace('/[^A-Za-z0-9]/', '', $productCode) ?? '';
        $barcode = $barcodePrefix.($safeCode !== '' ? $safeCode : $pluNo);
        $pluName = self::sanitizeComstorePluName((string) ($product->product_name ?? 'Product'));
        $fallbackUnitPrice = (string) ($defaults['unit_price'] ?? '1');
        $unitPrice = $price > 0
            ? self::formatComstoreUnitPrice($price)
            : ($fallbackUnitPrice !== '' ? $fallbackUnitPrice : '1');

        return [
            'plu_no' => $pluNo,
            'barcode' => $barcode,
            'plu_name' => $pluName,
            'unit_price' => $unitPrice,
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

    /**
     * Comstore PLU names must be English/ASCII — non-Latin characters cause E034.
     */
    public static function sanitizeComstorePluName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Product';
        }

        $name = strtr($name, [
            '—' => '-',
            '–' => '-',
            '‘' => "'",
            '’' => "'",
            '“' => '"',
            '”' => '"',
            '×' => 'x',
            '…' => '...',
            '°' => ' deg',
        ]);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($converted) && trim($converted) !== '') {
                $name = $converted;
            }
        }

        $name = preg_replace('/[^A-Za-z0-9 .,#\\-\/+()%&\']/u', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        if ($name === '') {
            return 'Product';
        }

        return mb_substr($name, 0, 50);
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
        $saleQty = self::formatWorkflowSaleQty($quantity);
        $wireQuantity = (float) $saleQty;

        return [
            'item_Name' => self::sanitizeComstorePluName($itemName !== '' ? $itemName : 'Product'),
            // Comstore sale workflow expects an empty Barcode; keep product_code for
            // Centrix matching against device SKU / "NO FIND PLU DATA for item …".
            'Barcode' => '',
            'product_code' => $productCode,
            'SalePrice' => self::formatWorkflowSalePrice($amount, $wireQuantity),
            'SaleQty' => $saleQty,
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

        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
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
        return $this->deviceResponseSuccessfulFromParts(
            $response->successful(),
            $response->status(),
            $responseData,
            $path,
        );
    }

    protected function deviceResponseSuccessfulFromParts(
        bool $httpSuccessful,
        int $httpStatus,
        ?array $responseData,
        string $path,
    ): bool {
        if (! $httpSuccessful) {
            return false;
        }

        foreach (['data', 'result', 'response', 'Response'] as $key) {
            if (is_array($responseData[$key] ?? null)) {
                $responseData = array_merge($responseData[$key], $responseData);
            }
        }

        if ($this->usesUploadPluDataPayload($path)) {
            if (! is_array($responseData)) {
                return false;
            }

            return filter_var($responseData['success'] ?? $responseData['Success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $normalizedPath = strtolower($path);
        if (str_contains($normalizedPath, '/api/init') || str_contains($normalizedPath, 'restart-device')) {
            if (! is_array($responseData)) {
                return false;
            }

            if (array_key_exists('success', $responseData) || array_key_exists('Success', $responseData)) {
                return filter_var($responseData['success'] ?? $responseData['Success'], FILTER_VALIDATE_BOOLEAN);
            }

            return strtoupper((string) ($responseData['status'] ?? '')) === 'OK';
        }

        return filter_var($responseData['success'] ?? $responseData['Success'] ?? false, FILTER_VALIDATE_BOOLEAN);
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
        ?array $tenders = null,
    ): array {
        $normalizedItems = array_map(function (array $item): array {
            $amount = round(max(0.0, (float) ($item['amount'] ?? 0)), 2);
            $productVat = (float) ($item['product_vat'] ?? 0);
            $vatRate = (float) ($item['vat_rate'] ?? 0);
            if ($vatRate <= 0 && $productVat > 0.0001 && $amount > $productVat) {
                $inferredRate = ($productVat / ($amount - $productVat)) * 100;
                $vatRate = abs($inferredRate - 8) < 1 ? 8 : 16;
            } elseif ($vatRate <= 0 && $productVat > 0.0001) {
                $vatRate = 16;
            }
            if ($vatRate > 0 && $amount > 0) {
                $productVat = round($amount - ($amount / (1 + ($vatRate / 100))), 2);
            } else {
                $productVat = 0.0;
            }

            return array_merge($item, [
                'amount' => $amount,
                'product_vat' => $productVat,
                'vat_rate' => $vatRate,
            ]);
        }, $orderItems);

        $summary = SalesVatCalculator::summarizeForLightStoresWorkflow($normalizedItems);
        $vat16NetTotal = $summary['vat16_net'];
        $vat16ValueTotal = $summary['vat16_value'];
        $vat8NetTotal = $summary['vat8_net'];
        $vat8ValueTotal = $summary['vat8_value'];
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
        $cashAmt = round((float) ($tenders['cash'] ?? $totalAmount), 2);
        $checkAmt = round((float) ($tenders['check'] ?? 0), 2);
        $cardAmt = round((float) ($tenders['card'] ?? 0), 2);
        // KRA validates sum(plu SaleAmount) against CashAmt — prefer line sum when they diverge.
        if ($tenders === null && abs($pluLineTotal - $cashAmt) > 0.009) {
            $cashAmt = $pluLineTotal;
        }

        $isCreditNote = $invoiceType === 'credit';

        $signStructure = [
            'SignType' => $this->isTest ? '0' : '1',
            'DiscAmt' => '0',
            'CashAmt' => number_format($cashAmt, 2, '.', ''),
            'CheckAmt' => number_format($checkAmt, 2, '.', ''),
            'CardAmt' => number_format($cardAmt, 2, '.', ''),
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
            'Vat E(8%) net' => number_format($vat8NetTotal, 2, '.', ''),
            'Vat E(8%) value' => number_format($vat8ValueTotal, 2, '.', ''),
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
    public function checkHealth(?int $waitSeconds = null): array
    {
        $url = $this->deviceBaseUrl.'/api/health';
        $agentWait = $waitSeconds ?? KraAgentBridge::HEALTH_WAIT_SECONDS;

        try {
            if ($this->usesAgentBridge()) {
                $proxied = $this->agentBridge->executeViaAgent(
                    $this->kraAgent,
                    'GET',
                    '/api/health',
                    null,
                    'json',
                    false,
                    $agentWait,
                );
                $body = json_decode($proxied['body'], true);
                $httpOk = $proxied['status'] >= 200 && $proxied['status'] < 300;
                $isArray = is_array($body);
                $statusOk = ! $isArray || ! isset($body['status'])
                    || strtoupper((string) $body['status']) === 'OK';
                $deviceConnected = ! $isArray || ! isset($body['deviceConnection'])
                    || strcasecmp((string) $body['deviceConnection'], 'Connected') === 0;
                $successful = $httpOk && $statusOk && $deviceConnected;
                $message = $this->healthMessage($body, $httpOk, $statusOk, $deviceConnected);

                if ($this->kraAgent) {
                    $this->agentBridge->touchAgent($this->kraAgent, null, true, '');
                }

                return [
                    'success' => $successful,
                    'reachable' => true,
                    'via_agent' => true,
                    'manual_start_required' => false,
                    'http_status' => $proxied['status'],
                    'url' => $url,
                    'message' => $message,
                    'device_connection' => $isArray ? ($body['deviceConnection'] ?? null) : null,
                    'api_service' => $isArray ? ($body['apiService'] ?? null) : null,
                    'device_version' => $isArray ? ($body['version'] ?? null) : null,
                    'response' => $isArray ? $body : ['body' => $proxied['body']],
                ];
            }

            $response = Http::timeout(min(8, max(3, $agentWait)))
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
                'via_agent' => false,
                'manual_start_required' => false,
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

            $raw = $e->getMessage();
            $manual = $this->usesAgentBridge() && (
                KraAgentBridge::isComstoreManualStartRequired($raw)
                || (bool) preg_match('/connection refused|actively refused|failed to connect|no connection could be made/i', $raw)
            );
            $comstoreUrl = $this->kraAgent?->comstore_base_url
                ?? $this->deviceBaseUrl
                ?? 'http://localhost:4000';

            if ($manual && $this->kraAgent) {
                $this->agentBridge->touchAgent(
                    $this->kraAgent,
                    null,
                    false,
                    KraAgentBridge::comstoreManualStartUserMessage((string) $comstoreUrl),
                );
            }

            $message = $manual
                ? KraAgentBridge::comstoreManualStartUserMessage((string) $comstoreUrl)
                : KraDeviceErrorTranslator::userMessage(
                    $this->usesAgentBridge()
                        ? 'Centrix KRA Agent / Comstore: '.$raw
                        : $raw
                );

            return [
                'success' => false,
                'reachable' => false,
                'via_agent' => $this->usesAgentBridge(),
                'manual_start_required' => $manual,
                'http_status' => null,
                'url' => $url,
                'message' => $message,
                'detail' => $raw,
                'device_connection' => null,
                'api_service' => null,
                'device_version' => null,
                'response' => null,
            ];
        }
    }

    /**
     * Finance "Test connection": Comstore /api/health + optional Fiscal hardware IP ping via agent.
     *
     * @return array<string, mixed>
     */
    public function testConnection(?int $waitSeconds = null): array
    {
        $wait = $waitSeconds ?? KraAgentBridge::ADMIN_HEALTH_WAIT_SECONDS;
        $health = $this->checkHealth($wait);
        $hardwareIp = trim($this->hardwareIp);
        if ($hardwareIp === '' && $this->kraAgent) {
            $hardwareIp = trim((string) ($this->kraAgent->device_hardware_ip ?? ''));
        }

        $deviceProbe = null;
        if ($this->usesAgentBridge() && $hardwareIp !== '') {
            $deviceProbe = $this->probeFiscalHardwareViaAgent($hardwareIp, min(10, $wait));
        }

        $parts = [];
        $comstoreOk = (bool) ($health['success'] ?? false);
        if ($comstoreOk) {
            $parts[] = (string) ($health['message'] ?? 'Comstore health OK.');
        } else {
            $parts[] = (string) ($health['message'] ?? 'Comstore health failed.');
            if (! empty($health['detail']) && is_string($health['detail'])
                && ! str_contains((string) $health['message'], $health['detail'])) {
                $parts[] = 'Detail: '.$health['detail'];
            }
        }

        $deviceOk = true;
        if (is_array($deviceProbe)) {
            $deviceOk = (bool) ($deviceProbe['success'] ?? false);
            $parts[] = (string) ($deviceProbe['message'] ?? (
                $deviceOk ? 'Fiscal hardware reachable.' : 'Fiscal hardware not reachable.'
            ));
            if ($this->kraAgent) {
                $this->agentBridge->touchAgent(
                    $this->kraAgent,
                    null,
                    $comstoreOk ? true : null,
                    $comstoreOk ? '' : (string) ($health['message'] ?? ''),
                    $deviceOk,
                    (string) ($deviceProbe['message'] ?? ''),
                    $hardwareIp !== '' ? $hardwareIp : null,
                    isset($deviceProbe['device_connection']) ? (string) $deviceProbe['device_connection'] : null,
                );
            }
        } elseif ($hardwareIp !== '') {
            $deviceOk = false;
            $parts[] = 'Fiscal hardware IP is set ('.$hardwareIp.') but could not be probed — Centrix KRA Agent must be online.';
        }

        $success = $comstoreOk && $deviceOk;

        return array_merge($health, [
            'success' => $success,
            'message' => implode(' ', array_filter($parts)),
            'comstore_ok' => $comstoreOk,
            'device_ok' => $deviceOk,
            'device_hardware_ip' => $hardwareIp !== '' ? $hardwareIp : null,
            'device_reachable' => is_array($deviceProbe) ? (bool) ($deviceProbe['success'] ?? false) : null,
            'device_ping_ok' => is_array($deviceProbe) ? ($deviceProbe['ping_ok'] ?? null) : null,
            'device_status_message' => is_array($deviceProbe) ? ($deviceProbe['message'] ?? null) : null,
            'manual_start_required' => (bool) ($health['manual_start_required'] ?? false),
            'expose_detail' => true,
        ]);
    }

    /**
     * Ask CentrixKraAgent to ICMP/TCP-ping the Smart VSCU hardware IP.
     *
     * @return array<string, mixed>|null
     */
    protected function probeFiscalHardwareViaAgent(string $hardwareIp, int $waitSeconds): ?array
    {
        if (! $this->usesAgentBridge()) {
            return null;
        }

        try {
            $proxied = $this->agentBridge->executeViaAgent(
                $this->kraAgent,
                'GET',
                '/agent/device-probe',
                $hardwareIp !== '' ? ['hardware_ip' => $hardwareIp] : null,
                'json',
                false,
                max(5, $waitSeconds),
            );
            $body = json_decode($proxied['body'], true);
            if (! is_array($body)) {
                return [
                    'success' => false,
                    'message' => trim((string) ($proxied['body'] ?: 'Device probe returned an empty response.')),
                    'ping_ok' => null,
                    'device_connection' => null,
                ];
            }

            return [
                'success' => (bool) ($body['success'] ?? $body['reachable'] ?? false),
                'message' => (string) ($body['message'] ?? 'Device probe completed.'),
                'ping_ok' => array_key_exists('ping_ok', $body) ? $body['ping_ok'] : null,
                'device_connection' => $body['device_connection'] ?? null,
                'hardware_ip' => $body['hardware_ip'] ?? $hardwareIp,
                'comstore_healthy' => $body['comstore_healthy'] ?? null,
                'response' => $body,
            ];
        } catch (\Throwable $e) {
            Log::warning('KRA fiscal hardware probe failed: '.$e->getMessage());

            return [
                'success' => false,
                'message' => KraDeviceErrorTranslator::userMessage($e->getMessage()),
                'detail' => $e->getMessage(),
                'ping_ok' => false,
                'device_connection' => null,
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
    protected function postToDevice(string $path, ?array $payload, array $context = []): array
    {
        $url = $this->deviceBaseUrl . $path;
        $checkout = ! empty($context['checkout']);
        $agentWait = (int) ($context['agent_wait_seconds']
            ?? ($checkout
                ? KraAgentBridge::CHECKOUT_COMMAND_WAIT_SECONDS
                : KraAgentBridge::COMMAND_WAIT_SECONDS));
        $httpTimeout = (int) ($context['http_timeout_seconds'] ?? ($checkout ? 20 : 45));
        $httpConnect = (int) ($context['http_connect_timeout_seconds'] ?? ($checkout ? 3 : 5));

        try {
            if ($this->usesAgentBridge()) {
                $proxied = $this->agentBridge->executeViaAgent(
                    $this->kraAgent,
                    'POST',
                    $path,
                    $payload,
                    'json',
                    false,
                    $agentWait,
                );
                $responseData = json_decode($proxied['body'], true);
                $httpSuccessful = $proxied['status'] >= 200 && $proxied['status'] < 300;
                $successful = $this->deviceResponseSuccessfulFromParts(
                    $httpSuccessful,
                    $proxied['status'],
                    is_array($responseData) ? $responseData : null,
                    $path,
                );

                if (! $httpSuccessful && is_array($responseData) && ! empty($responseData['message'])) {
                    return $this->deviceFailureResult(
                        (string) $responseData['message'],
                        $payload,
                        $this->mapResponse($responseData),
                        $path,
                    );
                }

                $fromJson = '';
                if (is_array($responseData)) {
                    foreach (['message', 'Message', 'error', 'Error', 'detail', 'Detail'] as $key) {
                        if (! empty($responseData[$key]) && is_string($responseData[$key])) {
                            $fromJson = trim($responseData[$key]);
                            break;
                        }
                    }
                }
                $agentError = trim((string) ($proxied['agent_error'] ?? ''));
                $rawMessage = $fromJson !== ''
                    ? $fromJson
                    : ($agentError !== ''
                        ? $agentError
                        : (string) ($proxied['body'] ?? 'Comstore request failed'));

                if (! $successful) {
                    return $this->deviceFailureResult(
                        $rawMessage,
                        $payload,
                        $this->mapResponse(is_array($responseData) ? $responseData : null),
                        $path,
                    );
                }

                return [
                    'success' => true,
                    'message' => $rawMessage,
                    'payload' => $payload,
                    'response' => $this->mapResponse(is_array($responseData) ? $responseData : null),
                    'via_agent' => true,
                ];
            }

            // Connect fast-fail: a flapping device IP must not pin PHP workers.
            // Checkout uses a tighter budget (~30s total including health).
            $request = Http::connectTimeout($httpConnect)
                ->timeout($httpTimeout)
                ->retry(1, 250, function ($exception) {
                    // Never retry connection refused / timeout / aborted — device is down.
                    return ! ($exception instanceof \Illuminate\Http\Client\ConnectionException);
                }, throw: false)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    ...$this->comstoreApiHeaders(),
                ]);
            $response = $payload === null
                ? $request->post($url)
                : $request->post($url, $payload);

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
                    $path,
                );
            }

            $rawMessage = is_array($responseData)
                ? ($responseData['message'] ?? $responseData['Message'] ?? $response->body())
                : $response->body();

            if (! $successful) {
                return $this->deviceFailureResult(
                    (string) $rawMessage,
                    $payload,
                    $this->mapResponse(is_array($responseData) ? $responseData : null),
                    $path,
                );
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
                'via_agent' => $this->usesAgentBridge(),
            ], $context));

            return $this->deviceFailureResult('Exception: ' . $e->getMessage(), $payload, null, $path);
        }
    }

    /** @return array<string, string> */
    protected function comstoreApiHeaders(): array
    {
        return $this->comstoreApiKey !== '' ? ['X-API-KEY' => $this->comstoreApiKey] : [];
    }

    /** @return array<string, mixed> */
    protected function deviceFailureResult(
        string $rawMessage,
        ?array $payload,
        ?array $response = null,
        string $path = '',
    ): array {
        $payload ??= [];
        $translated = KraDeviceErrorTranslator::translate($rawMessage);
        $message = (string) ($translated['message'] ?? '');
        $technical = (string) ($translated['technical_message'] ?? $rawMessage);
        $code = $translated['code'] ?? null;

        // Product upload path: Code 34/034 is PLU data invalid — never "receipt number".
        if (
            $this->usesUploadPluDataPayload($path)
            || str_contains(strtolower($path), 'register-plu')
            || isset($payload['plu_items'])
            || isset($payload['plu_data'])
        ) {
            if (in_array((string) $code, ['34', '034'], true)
                || KraDeviceErrorTranslator::looksLikePluUploadFailure($technical.$message)
            ) {
                $pluMessage = config('kra_device_errors.codes.034');
                if (is_string($pluMessage) && $pluMessage !== '') {
                    $message = $pluMessage;
                    $code = $code ?: '034';
                }
            }
        }

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
        $raw = $payload['plu_data']
            ?? $payload['PluData']
            ?? $payload['plu_items']
            ?? $payload['PluItems']
            ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $labels = [];
        foreach ($raw as $line) {
            if (! is_array($line)) {
                continue;
            }
            $name = trim((string) (
                $line['item_Name']
                ?? $line['ItemName']
                ?? $line['plu_name']
                ?? $line['product_name']
                ?? ''
            ));
            $code = trim((string) (
                $line['product_code']
                ?? $line['Barcode']
                ?? $line['barcode']
                ?? $line['plu_no']
                ?? $line['itemCd']
                ?? ''
            ));
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

        // Some Comstore / middleware builds nest the fiscal fields under data/result.
        foreach (['data', 'result', 'response', 'Response'] as $wrapKey) {
            $nested = $responseData[$wrapKey] ?? null;
            if (is_array($nested)) {
                $responseData = array_merge($nested, $responseData);
            }
        }

        $signatureLink = $this->sanitizeEtimsQrPayload($this->firstNonEmptyString(
            $responseData['signature_link'] ?? null,
            $responseData['Signature Link'] ?? null,
            $responseData['signatureLink'] ?? null,
            $responseData['SignatureLink'] ?? null,
            $responseData['qr_link'] ?? null,
            $responseData['qr_url'] ?? null,
            $responseData['QRCode'] ?? null,
            $responseData['qrCode'] ?? null,
            $responseData['qr_code'] ?? null,
            $responseData['verification_url'] ?? null,
            $responseData['verificationUrl'] ?? null,
            $responseData['InvoiceUrl'] ?? null,
            $responseData['invoice_url'] ?? null,
        ));

        $receiptSignature = $responseData['Receipt Signature']
            ?? $responseData['receipt_signature']
            ?? $responseData['signature']
            ?? null;

        // Device often returns the scannable eTIMS string as Receipt Signature
        // (sometimes with a junk "úb" year prefix) and leaves signature_link empty.
        if (! $this->looksLikeEtimsQrPayload($signatureLink)) {
            $fromReceipt = $this->sanitizeEtimsQrPayload($receiptSignature);
            if ($this->looksLikeEtimsQrPayload($fromReceipt)) {
                $signatureLink = $fromReceipt;
                $receiptSignature = null;
            }
        } elseif ($this->looksLikeEtimsQrPayload($receiptSignature)) {
            $receiptSignature = null;
        }

        return [
            'success' => $responseData['success'] ?? $responseData['Success'] ?? false,
            'message' => $responseData['message'] ?? '',
            'signature' => $responseData['signature'] ?? null,
            'serial_number' => $responseData['serial_number'] ?? null,
            'timestamp' => $responseData['timestamp'] ?? null,
            'signature_file_path' => $responseData['signature_file_path'] ?? null,
            'invoice_number' => $responseData['invoice_number'] ?? null,
            'scu_id' => $responseData['scu_id'] ?? null,
            'cu_inv_no' => $responseData['cu-inv-no'] ?? null,
            'internal_data' => $responseData['internal-data'] ?? null,
            'receipt_signature' => $receiptSignature,
            'signature_link' => $signatureLink,
            'version' => $responseData['version'] ?? null,
            'transmit_status' => $responseData['transmit_status'] ?? $responseData['transmitStatus'] ?? null,
            'error_code' => $responseData['error_code'] ?? $responseData['ErrorCode'] ?? null,
            'error_message' => $responseData['error_message'] ?? $responseData['ErrorMessage'] ?? null,
        ];
    }

    /**
     * Comstore sometimes replaces the "20" year prefix with junk bytes (prints as "úb26-10-08").
     * Recover the scannable payload so receipts can draw a QR instead of the raw string.
     */
    public function sanitizeEtimsQrPayload(mixed $raw): ?string
    {
        $text = (string) ($raw ?? '');
        if ($text === '') {
            return null;
        }

        // Comstore replaces the "20" year with a single non-UTF-8 byte (prints as "ú").
        // preg_replace() with /u returns null for that byte and used to discard the
        // whole verification string, so the receipt printed the URL as text.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $asLatin = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
            $text = is_string($asLatin) && $asLatin !== ''
                ? $asLatin
                : (iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: $text);
        }

        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text) ?? $text;
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        if (! is_string($collapsed)) {
            $collapsed = preg_replace('/\s+/', ' ', $text) ?? $text;
        }
        $text = trim($collapsed);
        if ($text === '') {
            return null;
        }

        if (preg_match('/(20\d{2}-\d{2}-\d{2}\s+\d{1,2}:\d{2}(?::\d{2})?\s+\S+\s+\S+\s+https:\/\/\S+)/i', $text, $full)) {
            return rtrim($full[1], '),.;');
        }

        if (preg_match('/(\d{2}-\d{2}-\d{2}\s+\d{1,2}:\d{2}(?::\d{2})?\s+\S+\s+\S+\s+https:\/\/\S+)/i', $text, $short)) {
            return '20'.rtrim($short[1], '),.;');
        }

        if (preg_match('/https:\/\/\S+/i', $text, $url)) {
            return rtrim($url[0], '),.;');
        }

        return $text;
    }

    public function looksLikeEtimsQrPayload(mixed $raw): bool
    {
        $text = $this->sanitizeEtimsQrPayload($raw);

        return is_string($text) && $text !== '' && (bool) preg_match('/https:\/\/\S+/i', $text);
    }

    protected function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value == null) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
