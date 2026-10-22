<?php

namespace App\Services\Kra;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\KraResponse;
use App\Models\Organization;
use App\Models\Sale;
use App\Services\Erp\CapabilityGate;
use App\Services\Sales\CheckoutKraSubmissionService;
use Illuminate\Support\Facades\Log;

class KraInvoiceUploadRetryService
{
    /** @return array{attempted: int, queued: int, still_pending: int, skipped: int} */
    public function retryPending(int $limit = 50, ?int $organizationId = null): array
    {
        $stats = ['attempted' => 0, 'queued' => 0, 'still_pending' => 0, 'skipped' => 0];
        $failedSales = KraResponse::query()
            ->where('status', 'failed')
            ->where('response_payload->document_type', 'sale')
            ->where('response_payload->soft_failed', true)
            ->whereNotNull('sale_id')
            ->whereRaw('kra_responses.id = (SELECT MAX(latest.id) FROM kra_responses AS latest WHERE latest.sale_id = kra_responses.sale_id)')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('kra_responses AS successful')
                    ->whereColumn('successful.sale_id', 'kra_responses.sale_id')
                    ->where('successful.status', 'success')
                    ->where('successful.response_payload->document_type', 'sale');
            })
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($failedSales as $response) {
            $failurePayload = is_array($response->response_payload) ? $response->response_payload : [];
            $failure = $failurePayload['technical_message']
                ?? $failurePayload['error_message']
                ?? $response->error_message;
            if (! KraDeviceErrorTranslator::isTransientConnectivityFailure($failure)) {
                $stats['skipped']++;
                continue;
            }

            $sale = Sale::query()->find((int) $response->sale_id);
            $organization = $sale
                ? Organization::query()->find((int) $sale->organization_id)
                : null;
            if (! $sale || ! $organization) {
                $stats['skipped']++;
                continue;
            }

            $finance = (new CapabilityGate($organization))->moduleSettings('finance') ?? [];
            if (empty($finance['enable_kra_device']) || ! empty($finance['kra_device_test_mode'])) {
                $stats['skipped']++;
                continue;
            }

            $customerPin = $sale->customer_num
                ? Customer::query()
                    ->where('organization_id', (int) $sale->organization_id)
                    ->where('customer_num', (int) $sale->customer_num)
                    ->value('kra_pin')
                : null;

            $stats['attempted']++;
            try {
                $retried = app(CheckoutKraSubmissionService::class)->submitForSale(
                    $sale,
                    new CapabilityGate($organization),
                    is_string($customerPin) && $customerPin !== '' ? $customerPin : null,
                );
                if ($retried?->status === 'success') {
                    $payload = is_array($retried->response_payload) ? $retried->response_payload : [];
                    if (($payload['online_upload_status'] ?? null) === 'queued'
                        || ($payload['online_upload_status'] ?? null) === 'transmitted') {
                        $stats['queued']++;
                    } else {
                        $stats['still_pending']++;
                    }
                } else {
                    $stats['still_pending']++;
                }
            } catch (\Throwable $e) {
                $stats['still_pending']++;
                Log::warning('KRA sale fiscalization retry failed', [
                    'sale_id' => $sale->id,
                    'organization_id' => $sale->organization_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $responses = KraResponse::query()
            ->where('status', 'success')
            ->where('response_payload->online_upload_status', 'pending')
            ->whereIn('response_payload->document_type', ['sale', 'credit_note'])
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('id')
            ->limit(max(1, $limit) * 4)
            ->get();

        foreach ($responses as $response) {
            $payload = is_array($response->response_payload) ? $response->response_payload : [];
            if (($payload['online_upload_status'] ?? null) !== 'pending') {
                continue;
            }
            if (! in_array(($payload['document_type'] ?? null), ['sale', 'credit_note'], true)) {
                continue;
            }

            $organization = Organization::query()->find((int) $response->organization_id);
            if (! $organization) {
                $stats['skipped']++;
                continue;
            }
            $finance = (new CapabilityGate($organization))->moduleSettings('finance') ?? [];
            if (empty($finance['enable_kra_device']) || ! empty($finance['kra_device_test_mode'])) {
                $stats['skipped']++;
                continue;
            }

            $stats['attempted']++;
            try {
                $device = KraDeviceService::fromSettings($finance, (int) $organization->id);
                $upload = $device->uploadPendingInvoices([
                    'agent_wait_seconds' => min(12, KraAgentBridge::COMMAND_WAIT_SECONDS),
                ]);
                if ($upload['success'] ?? false) {
                    $payload['online_upload_status'] = 'queued';
                    $payload['online_upload_response'] = $upload['response'] ?? null;
                    $payload['online_upload_error'] = null;
                    $payload['transmit_status'] = 'Invoices queued for manual upload to eTIMS.';
                    $stats['queued']++;
                } else {
                    $payload['online_upload_error'] = (string) ($upload['message'] ?? 'Could not queue eTIMS upload.');
                    $stats['still_pending']++;
                }
                $response->response_payload = $payload;
                $response->save();

                if (($payload['document_type'] ?? null) === 'credit_note') {
                    CreditNote::query()
                        ->where('kra_invoice_number', $response->invoice_number)
                        ->where('organization_id', $response->organization_id)
                        ->update(['kra_response_payload' => $payload]);
                }
            } catch (\Throwable $e) {
                $stats['still_pending']++;
                $payload['online_upload_error'] = $e->getMessage();
                $response->response_payload = $payload;
                $response->save();
                Log::warning('KRA invoice upload retry failed', [
                    'kra_response_id' => $response->id,
                    'organization_id' => $response->organization_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }
}