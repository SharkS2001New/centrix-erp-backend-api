<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class KraOperationsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
    ) {}

    public function deviceStatus(Request $request)
    {
        $user = $request->user();
        $finance = $this->erp->gateForUser($user)->moduleSettings('finance');
        $enabled = ! empty($finance['enable_kra_device']);

        $status = [
            'enabled' => $enabled,
            'device_ip' => trim((string) ($finance['kra_device_ip'] ?? '')),
            'serial_number' => trim((string) ($finance['kra_serial_number'] ?? '')),
            'test_mode' => (bool) ($finance['kra_device_test_mode'] ?? false),
            'reachable' => false,
            'message' => $enabled ? 'Device not probed yet.' : 'KRA device integration is disabled.',
        ];

        if (! $enabled) {
            return response()->json($status);
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
            $response = Http::timeout(8)->get($base);
            $status['reachable'] = $response->successful() || $response->status() < 500;
            $status['message'] = $status['reachable']
                ? 'Device responded (HTTP '.$response->status().').'
                : 'Device unreachable (HTTP '.$response->status().').';
        } catch (\Throwable $e) {
            $status['message'] = 'Could not reach device: '.$e->getMessage();
        }

        return response()->json($status);
    }

    public function retry(Request $request, int $kraResponse)
    {
        $row = KraResponse::findOrFail($kraResponse);
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
