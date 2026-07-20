<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraFiscalPolicy;
use Illuminate\Http\Request;
use InvalidArgumentException;

class KraOperationsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
    ) {}

    public function deviceStatus(Request $request)
    {
        $user = $request->user();
        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');
        $configured = KraFiscalPolicy::isDeviceConfigured($finance);

        $status = [
            'enabled' => $configured,
            'fiscalization_active' => KraFiscalPolicy::isFiscalizationActive($finance),
            'bypass_above_amount' => KraFiscalPolicy::bypassAboveAmount($finance),
            'device_ip' => trim((string) ($finance['kra_device_ip'] ?? '')),
            'device_hardware_ip' => trim((string) ($finance['kra_device_hardware_ip'] ?? '')),
            'serial_number' => trim((string) ($finance['kra_serial_number'] ?? '')),
            'test_mode' => (bool) ($finance['kra_device_test_mode'] ?? false),
            'reachable' => false,
            'device_connection' => null,
            'message' => $configured ? 'Device not probed yet.' : 'KRA device is not configured.',
        ];

        if (! $configured) {
            return response()->json($status);
        }

        if (! KraFiscalPolicy::isFiscalizationActive($finance)) {
            $status['message'] = 'Device is configured but sales fiscalization is turned off in Finance settings.';
        }

        try {
            $testFinance = array_merge($finance, ['enable_kra_device' => true]);
            $result = KraDeviceService::fromSettings($testFinance)->checkHealth();
            $status['reachable'] = (bool) ($result['reachable'] ?? false);
            $status['health_url'] = $result['url'] ?? null;
            $status['http_status'] = $result['http_status'] ?? null;
            $status['device_connection'] = $result['device_connection'] ?? null;
            $status['api_service'] = $result['api_service'] ?? null;
            $status['device_version'] = $result['device_version'] ?? null;
            $status['message'] = (string) ($result['message'] ?? 'Health check completed.');
            if (! empty($result['response']) && is_array($result['response'])) {
                $status['device_response'] = $result['response'];
            }
        } catch (\Throwable $e) {
            $status['message'] = 'Could not reach device: '.$e->getMessage();
        }

        return response()->json($status);
    }

    public function deviceHealth(Request $request)
    {
        $user = $request->user();
        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');

        $draft = $request->validate([
            'kra_device_ip' => 'sometimes|nullable|string|max:250',
            'kra_device_hardware_ip' => 'sometimes|nullable|string|max:100',
            'kra_serial_number' => 'sometimes|nullable|string|max:100',
            'kra_device_test_mode' => 'sometimes|boolean',
        ]);

        $testFinance = array_merge($finance, $draft, ['enable_kra_device' => true]);

        $ip = trim((string) ($testFinance['kra_device_ip'] ?? ''));
        if ($ip === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter the device IP / URL before testing the connection.',
            ], 422);
        }

        try {
            $result = KraDeviceService::fromSettings($testFinance)->checkHealth();
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'KRA device health check failed: '.$e->getMessage(),
            ], 502);
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502);
    }

    public function deviceInit(Request $request)
    {
        $user = $request->user();
        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');

        $draft = $this->validateKraDeviceDraft($request);

        $testFinance = array_merge($finance, $draft, ['enable_kra_device' => true]);

        $serial = trim((string) ($testFinance['kra_serial_number'] ?? ''));
        if ($serial === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter the device serial number before initializing.',
            ], 422);
        }

        $hardwareIp = KraDeviceService::resolveHardwareIp($testFinance);
        if ($hardwareIp === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter the fiscal device hardware IP (Smart VSCU LAN address). Required when the API URL is a hostname.',
            ], 422);
        }

        try {
            $result = KraDeviceService::fromSettings($testFinance)->initializeDevice($hardwareIp);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'KRA device initialization failed: '.$e->getMessage(),
            ], 502);
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502);
    }

    public function deviceRestart(Request $request)
    {
        $user = $request->user();
        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');

        $draft = $this->validateKraDeviceDraft($request);
        $testFinance = array_merge($finance, $draft, ['enable_kra_device' => true]);

        $hardwareIp = KraDeviceService::resolveHardwareIp($testFinance);
        if ($hardwareIp === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter the fiscal device hardware IP before restarting the device.',
            ], 422);
        }

        try {
            $result = KraDeviceService::fromSettings($testFinance)->restartDevice($hardwareIp);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'KRA device restart failed: '.$e->getMessage(),
            ], 502);
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502);
    }

    /** @return array<string, mixed> */
    protected function validateKraDeviceDraft(Request $request): array
    {
        return $request->validate([
            'kra_device_ip' => 'sometimes|nullable|string|max:250',
            'kra_device_hardware_ip' => 'sometimes|nullable|string|max:100',
            'kra_serial_number' => 'sometimes|nullable|string|max:100',
            'kra_pin_number' => 'sometimes|nullable|string|max:45',
            'kra_device_test_mode' => 'sometimes|boolean',
        ]);
    }

    public function retry(Request $request, int $kraResponse)
    {
        $user = $request->user();
        $orgId = (int) $this->erp->gateForRequest($request)->organization()?->id;

        $row = KraResponse::query()
            ->whereHas('sale', fn ($saleQuery) => $saleQuery->where('organization_id', $orgId))
            ->findOrFail($kraResponse);
        if ($row->status === 'success') {
            return response()->json(['message' => 'Receipt already succeeded.'], 422);
        }

        $sale = Sale::with('items')->find($row->sale_id);
        if (! $sale) {
            return response()->json(['message' => 'Linked sale not found.'], 422);
        }

        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');
        if (empty($finance['enable_kra_device'])) {
            return response()->json(['message' => 'Enable KRA device in Finance settings first.'], 422);
        }

        $service = KraDeviceService::fromSettings($finance);
        $allocator = app(\App\Services\Kra\KraTraderInvoiceAllocator::class);
        $invoiceNumber = $allocator->extractFromKraResponse($row)
            ?: $service->traderInvoiceForSale($sale, $finance);
        $orderItems = $sale->items->map(fn ($line) => [
            'product_name' => $line->product_name ?? $line->product_code,
            'product_code' => $line->product_code,
            'quantity' => (float) $line->quantity,
            'amount' => (float) $line->amount,
            'product_vat' => (float) ($line->product_vat ?? 0),
        ])->all();

        $result = $service->sendSale(
            $orderItems,
            (float) $sale->order_total,
            $invoiceNumber,
            null,
        );

        if (! ($result['success'] ?? false)) {
            $row->update([
                'status' => 'failed',
                'error_message' => $result['message'] ?? 'Retry failed',
                'request_payload' => $result['payload'] ?? null,
                'response_payload' => $result['response'] ?? null,
            ]);

            return response()->json([
                'message' => $result['message'] ?? 'KRA device submission failed.',
                'kra_response' => $row->fresh(),
            ], 422);
        }

        $mapped = $result['response'] ?? [];
        $row->update([
            'invoice_number' => $mapped['invoice_number'] ?? $invoiceNumber,
            'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
            'signature_link' => $mapped['signature_link'] ?? null,
            'serial_number' => $mapped['serial_number'] ?? null,
            'kra_timestamp' => $mapped['timestamp'] ?? null,
            'request_payload' => $result['payload'] ?? null,
            'response_payload' => $mapped,
            'status' => 'success',
            'error_message' => null,
        ]);

        return response()->json([
            'message' => 'KRA receipt submitted successfully.',
            'kra_response' => $row->fresh(),
        ]);
    }
}