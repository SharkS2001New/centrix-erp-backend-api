<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraFiscalPolicy;
use Illuminate\Http\Request;

class KraOperationsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
    ) {}

    public function deviceStatus(Request $request)
    {
        $user = $request->user();
        $finance = $this->erp->gateForUser($user)->moduleSettings('finance');
        $configured = KraFiscalPolicy::isDeviceConfigured($finance);

        $status = [
            'enabled' => $configured,
            'fiscalization_active' => KraFiscalPolicy::isFiscalizationActive($finance),
            'bypass_above_amount' => KraFiscalPolicy::bypassAboveAmount($finance),
            'device_ip' => trim((string) ($finance['kra_device_ip'] ?? '')),
            'serial_number' => trim((string) ($finance['kra_serial_number'] ?? '')),
            'test_mode' => (bool) ($finance['kra_device_test_mode'] ?? false),
            'reachable' => false,
            'message' => $configured ? 'Device not probed yet.' : 'KRA device is not configured.',
        ];

        if (! $configured) {
            return response()->json($status);
        }

        if (! KraFiscalPolicy::isFiscalizationActive($finance)) {
            $status['message'] = 'Device is configured but sales fiscalization is turned off in Finance settings.';
        }

        $base = trim((string) ($finance['kra_device_ip'] ?? ''));
        if ($base === '') {
            $status['message'] = 'Configure device IP under Admin → Settings → Finance.';

            return response()->json($status);
        }

        if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
            $base = 'http://'.$base;
        }
        $base = rtrim($base, '/');

        try {
            $testFinance = array_merge($finance, ['enable_kra_device' => true]);
            $result = KraDeviceService::fromSettings($testFinance)->checkHealth();
            $status['reachable'] = (bool) ($result['reachable'] ?? false);
            $status['health_url'] = $result['url'] ?? ($base.'/api/health');
            $status['http_status'] = $result['http_status'] ?? null;
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
        $finance = $this->erp->gateForUser($user)->moduleSettings('finance');

        $draft = $request->validate([
            'kra_device_ip' => 'sometimes|nullable|string|max:250',
            'kra_serial_number' => 'sometimes|nullable|string|max:100',
            'kra_pin_number' => 'sometimes|nullable|string|max:45',
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

        $pin = trim((string) ($testFinance['kra_pin_number'] ?? ''));
        if ($pin === '' || $pin === '********') {
            $pin = trim((string) ($finance['kra_pin_number'] ?? ''));
            $testFinance['kra_pin_number'] = $pin;
        }

        if ($pin === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter the shop KRA PIN before testing the connection.',
            ], 422);
        }

        try {
            $result = KraDeviceService::fromSettings($testFinance)->checkHealth();
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502);
    }

    public function retry(Request $request, int $kraResponse)
    {
        $user = $request->user();
        $orgId = (int) $this->erp->gateForUser($user)->organization()?->id;

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

        $user = $request->user();
        $finance = $this->erp->gateForUser($user)->moduleSettings('finance');
        if (empty($finance['enable_kra_device'])) {
            return response()->json(['message' => 'Enable KRA device in Finance settings first.'], 422);
        }

        $service = KraDeviceService::fromSettings($finance);
        $invoiceNumber = $row->invoice_number ?: $service->generateInvoiceNumber();
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
