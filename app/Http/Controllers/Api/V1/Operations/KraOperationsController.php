<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Jobs\ReFiscalizeKraInvoicesLiveJob;
use App\Models\CreditNote;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Background\BackgroundTaskService;
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\Erp\ErpContext;
use App\Services\Auth\UserAccessService;
use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraFiscalPolicy;
use App\Services\Kra\KraTraderInvoiceAllocator;
use App\Services\Sales\CreditNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class KraOperationsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
    ) {}

    public function deviceStatus(Request $request)
    {
        $gate = $this->erp->gateForRequest($request);
        $finance = $gate->moduleSettings('finance');
        $configured = KraFiscalPolicy::isDeviceConfigured($finance);
        $orgId = $gate->organization()?->id ? (int) $gate->organization()->id : null;

        $status = [
            'enabled' => $configured,
            'fiscalization_active' => KraFiscalPolicy::isFiscalizationActive($finance),
            'kra_sales_scope' => KraFiscalPolicy::salesScope($finance),
            'bypass_above_amount' => KraFiscalPolicy::bypassAboveAmount($finance),
            'device_ip' => trim((string) ($finance['kra_device_ip'] ?? '')),
            'device_hardware_ip' => trim((string) ($finance['kra_device_hardware_ip'] ?? '')),
            'serial_number' => trim((string) ($finance['kra_serial_number'] ?? '')),
            'test_mode' => (bool) ($finance['kra_device_test_mode'] ?? false),
            'enable_kra_agent' => (bool) ($finance['enable_kra_agent'] ?? false),
            'reachable' => false,
            'device_connection' => null,
            'message' => $configured ? 'Device not probed yet.' : 'KRA device is not configured.',
        ];

        if (! $configured) {
            return response()->json($status);
        }

        if (! KraFiscalPolicy::isFiscalizationActive($finance)) {
            $status['message'] = 'Device is configured but sales fiscalization is turned off in Finance settings.';
        } elseif (($scope = KraFiscalPolicy::salesScope($finance)) === KraFiscalPolicy::SCOPE_MOBILE) {
            $status['message'] = 'KRA fiscalization is limited to mobile sales.';
        } elseif ($scope === KraFiscalPolicy::SCOPE_ERP) {
            $status['message'] = 'KRA fiscalization is limited to ERP sales (POS and backoffice).';
        }

        try {
            $testFinance = array_merge($finance, ['enable_kra_device' => true, 'enable_kra_agent' => true]);
            $result = KraDeviceService::fromSettings($testFinance, $orgId)->checkHealth();
            $status['reachable'] = (bool) ($result['reachable'] ?? false);
            $status['via_agent'] = (bool) ($result['via_agent'] ?? false);
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

    public function liveReplayCandidates(Request $request)
    {
        $filters = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'branch_id' => 'nullable|integer',
            'channel' => 'nullable|in:pos,mobile,backend',
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:200',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = $this->liveReplayCandidateQuery($request, $filters);

        return response()->json(
            $query
                ->select('kra_response_id', 'sale_id', 'order_no', 'sale_order_num', 'pos_order_num', 'sale_date')
                ->orderBy('sale_date')
                ->orderBy('kra_response_id')
                ->paginate((int) ($filters['per_page'] ?? 200))
        );
    }

    public function queueLiveReplay(Request $request, BackgroundTaskService $tasks)
    {
        $filters = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'branch_id' => 'nullable|integer',
            'channel' => 'nullable|in:pos,mobile,backend',
            'q' => 'nullable|string|max:100',
        ]);
        $query = $this->liveReplayCandidateQuery($request, $filters);
        $responseIds = $query->orderBy('sale_date')
            ->orderBy('kra_response_id')
            ->pluck('kra_response_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        abort_if($responseIds === [], 422, 'No eligible test-mode invoices were found for the applied sale-date range.');

        $user = $request->user();
        abort_unless($user, 401);
        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');
        abort_if(empty($finance['enable_kra_device']), 422, 'Enable KRA device in Finance settings first.');
        abort_if(
            ! empty($finance['kra_device_test_mode']),
            422,
            'Turn off KRA device test mode in Finance settings before re-fiscalizing live.',
        );
        $organizationId = app(UserAccessService::class)->organizationId($user, $request);
        $tasks->assertNoBlockingTask($user);
        $task = $tasks->create('kra_live_replay', $user, [
            'kra_response_ids' => $responseIds,
            'target_organization_id' => $organizationId,
        ]);
        $tasks->dispatch(ReFiscalizeKraInvoicesLiveJob::class, $task);

        return response()->json([
            'message' => 'Live KRA re-fiscalization queued.',
            'task_id' => $task->id,
            'queued' => true,
            'total' => count($responseIds),
        ], 202);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Query\Builder
     */
    protected function liveReplayCandidateQuery(Request $request, array $filters)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $access = app(UserAccessService::class);
        $orgId = $access->organizationId($user, $request);
        abort_unless($orgId, 403);

        $requestedBranchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $limitedBranchId = $access->branchId($user);
        if ($limitedBranchId !== null && $requestedBranchId !== null && $requestedBranchId !== $limitedBranchId) {
            abort(403, 'You can only re-fiscalize invoices for your assigned branch.');
        }
        $branchId = $limitedBranchId ?? $requestedBranchId;

        $query = DB::table('v_kra_receipts_replay')
            ->where('organization_id', $orgId)
            ->whereBetween('sale_date', [$filters['from_date'], $filters['to_date']])
            ->where('status', 'success')
            ->where('document_type', 'sale')
            ->where('is_test', 1)
            ->where('is_live_replay_in_progress', 0)
            ->when($branchId !== null, fn ($builder) => $builder->where('branch_id', $branchId))
            ->when(isset($filters['channel']), fn ($builder) => $builder->where('channel', $filters['channel']))
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('kra_responses as live')
                    ->whereColumn('live.sale_id', 'v_kra_receipts_replay.sale_id')
                    ->where('live.status', 'success')
                    ->whereRaw(
                        "NOT (
                            LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(live.request_payload, '$.is_test')), '')) IN ('true', '1')
                            OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(live.request_payload, '$.sign_structure.SignType')), '') = '0'
                        )"
                    )
                    ->whereRaw(
                        "LOWER(COALESCE(
                            JSON_UNQUOTE(JSON_EXTRACT(live.response_payload, '$.document_type')),
                            JSON_UNQUOTE(JSON_EXTRACT(live.request_payload, '$.sign_structure.InvoiceType')),
                            'sale'
                        )) NOT IN ('credit_note', 'credit', 'creditnote')"
                    );
            });

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('order_no', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%")
                    ->orWhere('sale_id', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function deviceHealth(Request $request)
    {
        $gate = $this->erp->gateForRequest($request);
        $finance = $gate->moduleSettings('finance');
        $orgId = $gate->organization()?->id ? (int) $gate->organization()->id : null;

        $draft = $request->validate([
            'kra_device_ip' => 'sometimes|nullable|string|max:250',
            'kra_device_hardware_ip' => 'sometimes|nullable|string|max:100',
            'kra_serial_number' => 'sometimes|nullable|string|max:100',
            'kra_device_test_mode' => 'sometimes|boolean',
            'enable_kra_agent' => 'sometimes|boolean',
        ]);

        $testFinance = array_merge($finance, $draft, [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
        ]);

        if (trim((string) ($testFinance['kra_device_ip'] ?? '')) === '') {
            $testFinance['kra_device_ip'] = 'http://localhost:4000';
        }

        try {
            $result = KraDeviceService::fromSettings($testFinance, $orgId)->testConnection();
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'expose_detail' => true,
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'KRA device health check failed: '.$e->getMessage(),
                'detail' => $e->getMessage(),
                'expose_detail' => true,
            ], 200);
        }

        // Always 200 so the UI can show the real Comstore/device message (not a generic network toast).
        return response()->json(array_merge($result, [
            'expose_detail' => true,
        ]));
    }

    public function deviceUploadPendingInvoices(Request $request)
    {
        $gate = $this->erp->gateForRequest($request);
        $finance = $gate->moduleSettings('finance');
        $orgId = $gate->organization()?->id ? (int) $gate->organization()->id : null;
        $draft = $this->validateKraDeviceDraft($request);
        $testFinance = array_merge($finance, $draft, [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
        ]);

        if (trim((string) ($testFinance['kra_serial_number'] ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter the device serial number before uploading pending invoices.',
            ], 422);
        }

        if (! empty($testFinance['kra_device_test_mode'])) {
            return response()->json([
                'success' => false,
                'message' => 'Turn off device test mode before uploading invoices to KRA.',
            ], 422);
        }

        try {
            $result = KraDeviceService::fromSettings($testFinance, $orgId)->uploadPendingInvoices();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Could not queue pending invoices for KRA upload.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        $result['message'] = ($result['success'] ?? false)
            ? 'Comstore queued pending invoices on this device for eTIMS upload.'
            : (string) ($result['message'] ?? 'Comstore could not queue pending invoices for eTIMS upload.');

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502);
    }

    public function deviceInit(Request $request)
    {
        $gate = $this->erp->gateForRequest($request);
        $finance = $gate->moduleSettings('finance');
        $orgId = $gate->organization()?->id ? (int) $gate->organization()->id : null;

        $draft = $this->validateKraDeviceDraft($request);

        $testFinance = array_merge($finance, $draft, ['enable_kra_device' => true, 'enable_kra_agent' => true]);

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
            $result = KraDeviceService::fromSettings($testFinance, $orgId)->initializeDevice($hardwareIp);
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
        $gate = $this->erp->gateForRequest($request);
        $finance = $gate->moduleSettings('finance');
        $orgId = $gate->organization()?->id ? (int) $gate->organization()->id : null;

        $draft = $this->validateKraDeviceDraft($request);
        $testFinance = array_merge($finance, $draft, ['enable_kra_device' => true, 'enable_kra_agent' => true]);

        $hardwareIp = KraDeviceService::resolveHardwareIp($testFinance);
        if ($hardwareIp === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter the fiscal device hardware IP before restarting the device.',
            ], 422);
        }

        try {
            $result = KraDeviceService::fromSettings($testFinance, $orgId)->restartDevice($hardwareIp);
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

        $sale = Sale::with(['items.product.vat', 'customer', 'payments.paymentMethod'])->find($row->sale_id);
        if (! $sale) {
            return response()->json(['message' => 'Linked sale not found.'], 422);
        }

        $gate = $this->erp->gateForRequest($request);
        $finance = $gate->moduleSettings('finance');
        if (empty($finance['enable_kra_device'])) {
            return response()->json(['message' => 'Enable KRA device in Finance settings first.'], 422);
        }

        $credits = app(CreditNoteService::class);
        if ($credits->isCreditNoteDocument($row)) {
            return $this->retryCreditNote($request, $row, $sale, $finance, $credits);
        }

        $service = KraDeviceService::fromSettings(
            $finance,
            $gate->organization()?->id ? (int) $gate->organization()->id : null,
        );
        $allocator = app(\App\Services\Kra\KraTraderInvoiceAllocator::class);
        $invoiceNumber = $allocator->extractFromKraResponse($row)
            ?: $service->traderInvoiceForSale($sale, $finance);
        $orderItems = \App\Services\Kra\KraSaleFiscalBuilder::orderItemsFromSale($sale);
        $buyerPin = trim((string) ($sale->customer?->kra_pin ?? ''));
        $buyerPin = $buyerPin !== '' ? $buyerPin : null;
        $tenders = \App\Services\Kra\KraSaleFiscalBuilder::tendersFromSale($sale);

        $result = $service->sendSale(
            $orderItems,
            (float) $sale->order_total,
            $invoiceNumber,
            $buyerPin,
            ['tenders' => $tenders],
        );

        if (! ($result['success'] ?? false)) {
            $row->update([
                'status' => 'failed',
                'error_message' => $result['message'] ?? 'Retry failed',
                'request_payload' => $result['payload'] ?? null,
                'response_payload' => array_merge(
                    is_array($result['response'] ?? null) ? $result['response'] : [],
                    [
                        'technical_message' => $result['technical_message'] ?? null,
                        'error_code' => $result['error_code'] ?? null,
                    ],
                ),
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
            'response_payload' => array_merge(is_array($mapped) ? $mapped : [], [
                'document_type' => 'sale',
            ]),
            'status' => 'success',
            'error_message' => null,
        ]);

        // Drop completed-sale detail cache so reprint picks up the new signature_link.
        app(CompletedSalesCacheService::class)->invalidateForSale($sale);

        return response()->json([
            'message' => 'KRA receipt submitted successfully.',
            'kra_response' => $row->fresh(),
        ]);
    }

    public function reFiscalizeLive(Request $request, int $kraResponse)
    {
        $gate = $this->erp->gateForRequest($request);
        $orgId = (int) $gate->organization()?->id;
        $row = KraResponse::query()
            ->whereHas('sale', fn ($query) => $query->where('organization_id', $orgId))
            ->findOrFail($kraResponse);

        if ($row->status !== 'success' || ! $this->isTestFiscalization($row)) {
            return response()->json([
                'message' => 'Only successfully signed test-mode invoices can be re-fiscalized live.',
            ], 422);
        }

        $sale = Sale::with(['items.product.vat', 'customer', 'payments.paymentMethod'])
            ->where('organization_id', $orgId)
            ->find($row->sale_id);
        if (! $sale) {
            return response()->json(['message' => 'Linked sale not found.'], 422);
        }

        $credits = app(CreditNoteService::class);
        if ($credits->isCreditNoteDocument($row)) {
            return response()->json([
                'message' => 'Credit notes cannot be re-fiscalized using the invoice replay action.',
            ], 422);
        }

        $finance = $gate->moduleSettings('finance');
        if (empty($finance['enable_kra_device'])) {
            return response()->json(['message' => 'Enable KRA device in Finance settings first.'], 422);
        }
        if ((bool) ($finance['kra_device_test_mode'] ?? config('app.env') !== 'production')) {
            return response()->json([
                'message' => 'Turn off KRA device test mode in Finance settings before re-fiscalizing live.',
            ], 422);
        }
        if ($sale->items->isEmpty()) {
            return response()->json(['message' => 'Sale has no items to re-fiscalize.'], 422);
        }

        $claim = DB::transaction(function () use ($row, $sale, $credits, $request) {
            Sale::query()
                ->where('organization_id', $sale->organization_id)
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $responses = KraResponse::query()
                ->where('sale_id', $sale->id)
                ->lockForUpdate()
                ->get();
            $lockedRow = $responses->firstWhere('id', $row->id);
            if (! $lockedRow || $lockedRow->status !== 'success' || ! $this->isTestFiscalization($lockedRow)) {
                return ['error' => 'This test invoice is no longer eligible for live re-fiscalization.'];
            }
            $lockedPayload = is_array($lockedRow->response_payload) ? $lockedRow->response_payload : [];
            if (($lockedPayload['live_replay_status'] ?? null) === 'processing') {
                return ['error' => 'A live re-fiscalization is already in progress for this sale.'];
            }

            foreach ($responses as $candidate) {
                if ($candidate->id === $lockedRow->id) {
                    continue;
                }
                $candidatePayload = is_array($candidate->response_payload) ? $candidate->response_payload : [];
                if (($candidatePayload['live_replay_status'] ?? null) === 'processing') {
                    return ['error' => 'A live re-fiscalization is already in progress for this sale.'];
                }
                if (
                    $candidate->status === 'success'
                    && ! $credits->isCreditNoteDocument($candidate)
                    && ! $this->isTestFiscalization($candidate)
                ) {
                    return ['error' => 'This sale already has a successful live KRA invoice.'];
                }
            }

            $oldResponsePayload = is_array($lockedRow->response_payload) ? $lockedRow->response_payload : [];
            $oldRequestPayload = $lockedRow->request_payload;
            $oldErrorMessage = $lockedRow->error_message;
            $markedResponsePayload = $oldResponsePayload;
            $markedResponsePayload['live_replay_status'] = 'processing';
            $lockedRow->update(['response_payload' => $markedResponsePayload]);

            return [
                'row' => $lockedRow,
                'old_response_payload' => $oldResponsePayload,
                'old_request_payload' => $oldRequestPayload,
                'old_error_message' => $oldErrorMessage,
            ];
        });
        if (isset($claim['error'])) {
            return response()->json(['message' => $claim['error']], 422);
        }
        $row = $claim['row'];
        try {
            $service = KraDeviceService::fromSettings($finance, $orgId);
            $invoiceNumber = app(KraTraderInvoiceAllocator::class)->forOrganization($orgId);
            $orderItems = \App\Services\Kra\KraSaleFiscalBuilder::orderItemsFromSale($sale);
            $buyerPin = trim((string) ($sale->customer?->kra_pin ?? ''));
            $buyerPin = $buyerPin !== '' ? $buyerPin : null;
            $tenders = \App\Services\Kra\KraSaleFiscalBuilder::tendersFromSale($sale);
            $result = $service->sendSale(
                $orderItems,
                (float) $sale->order_total,
                $invoiceNumber,
                $buyerPin,
                ['tenders' => $tenders],
            );
        } catch (\Throwable $e) {
            $row->update([
                'response_payload' => $claim['old_response_payload'],
                'error_message' => $claim['old_error_message'],
            ]);
            report($e);
            throw $e;
        }
        if (! ($result['success'] ?? false)) {
            Log::warning('Live KRA re-fiscalization failed; preserving test receipt', [
                'kra_response_id' => $row->id,
                'sale_id' => $sale->id,
                'user_id' => $request->user()?->id,
                'message' => $result['message'] ?? 'KRA device submission failed.',
            ]);
            $row->update([
                'response_payload' => $claim['old_response_payload'],
                'error_message' => $claim['old_error_message'],
            ]);

            return response()->json([
                'message' => $result['message'] ?? 'KRA device submission failed.',
            ], 422);
        }

        $mapped = is_array($result['response'] ?? null) ? $result['response'] : [];
        $mappedInvoiceNumber = trim((string) ($mapped['invoice_number'] ?? ''));
        $invoiceNumberAlreadyUsed = $mappedInvoiceNumber !== ''
            && KraResponse::query()
                ->where('organization_id', $orgId)
                ->where('invoice_number', $mappedInvoiceNumber)
                ->where('id', '!=', $row->id)
                ->exists();
        $testHistory = [
            'invoice_number' => $row->invoice_number,
            'receipt_signature' => $row->receipt_signature,
            'signature_link' => $row->signature_link,
            'serial_number' => $row->serial_number,
            'kra_timestamp' => $row->kra_timestamp,
            'error_message' => $claim['old_error_message'],
            'request_payload' => $claim['old_request_payload'],
            'response_payload' => $claim['old_response_payload'],
            'replaced_at' => now()->toIso8601String(),
            'replaced_by' => $request->user()?->id,
        ];
        $row->update([
            'invoice_number' => $invoiceNumberAlreadyUsed || $mappedInvoiceNumber === ''
                ? $invoiceNumber
                : $mappedInvoiceNumber,
            'receipt_signature' => $mapped['receipt_signature'] ?? $mapped['signature'] ?? null,
            'signature_link' => $mapped['signature_link'] ?? null,
            'serial_number' => $mapped['serial_number'] ?? null,
            'kra_timestamp' => $mapped['timestamp'] ?? null,
            'request_payload' => $result['payload'] ?? null,
            'response_payload' => array_merge($mapped, [
                'document_type' => 'sale',
                'device_invoice_number' => $mapped['invoice_number'] ?? null,
                'replaced_test_attempt' => $testHistory,
            ]),
            'status' => 'success',
            'error_message' => null,
        ]);

        app(CompletedSalesCacheService::class)->invalidateForSale($sale);

        return response()->json([
            'message' => 'Invoice re-fiscalized successfully in live mode.',
            'kra_response' => $row->fresh(),
        ]);
    }

    protected function isTestFiscalization(KraResponse $row): bool
    {
        $payload = is_array($row->request_payload) ? $row->request_payload : [];
        $signType = $payload['sign_structure']['SignType'] ?? null;

        return ($payload['is_test'] ?? false) === true
            || in_array(strtolower((string) ($payload['is_test'] ?? '')), ['true', '1'], true)
            || (string) $signType === '0';
    }

    /**
     * A failed credit note must be retried as a credit note. Resubmitting it as a
     * sale reuses the receipt number and KRA rejects it again (code 314).
     *
     * @param  array<string, mixed>  $finance
     */
    protected function retryCreditNote(
        Request $request,
        KraResponse $row,
        Sale $sale,
        array $finance,
        CreditNoteService $credits,
    ) {
        $payload = is_array($row->response_payload) ? $row->response_payload : [];
        $creditNoteId = (int) ($payload['credit_note_id'] ?? 0);
        $note = $creditNoteId > 0
            ? CreditNote::query()->where('organization_id', $sale->organization_id)->find($creditNoteId)
            : null;

        if ($note) {
            $updated = $credits->attemptKraForCreditNote($note);
            $fresh = $row->fresh();
            if ((string) $updated->kra_status === 'success') {
                return response()->json([
                    'message' => 'KRA credit note submitted successfully.',
                    'kra_response' => $fresh,
                ]);
            }

            if ((string) $updated->kra_status === 'pending') {
                return response()->json([
                    'success' => true,
                    'queued' => true,
                    'message' => 'KRA could not be reached immediately. The credit note is queued and will retry automatically.',
                    'kra_response' => $fresh,
                ], 202);
            }

            return response()->json([
                'message' => $updated->kra_error_message ?: 'KRA credit note failed.',
                'kra_response' => $fresh,
            ], 422);
        }

        $source = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'success')
            ->orderBy('id')
            ->get()
            ->first(fn (KraResponse $candidate) => ! $credits->isCreditNoteDocument($candidate));

        try {
            $credited = $credits->fiscalCreditSaleOnly(
                $sale,
                $request->user(),
                $finance,
                is_string($payload['refund_reason_code'] ?? null) ? $payload['refund_reason_code'] : null,
                $source,
                $row,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'kra_response' => $row->fresh(),
            ], 422);
        }

        if ($credited->status === 'pending') {
            return response()->json([
                'success' => true,
                'queued' => true,
                'message' => 'KRA could not be reached immediately. The credit note is queued and will retry automatically; the Centrix sale was not changed.',
                'kra_response' => $credited,
            ], 202);
        }

        return response()->json([
            'message' => 'KRA credit note submitted successfully.',
            'kra_response' => $credited,
        ]);
    }

    /**
     * Fiscal-only credit on the KRA device for a successful original invoice.
     * Does not create a Centrix customer return or change the sale.
     */
    public function credit(Request $request, int $kraResponse)
    {
        $orgId = (int) $this->erp->gateForRequest($request)->organization()?->id;

        $row = KraResponse::query()
            ->whereHas('sale', fn ($saleQuery) => $saleQuery->where('organization_id', $orgId))
            ->findOrFail($kraResponse);

        $data = $request->validate([
            'refund_reason_code' => 'nullable|string|max:2',
        ]);

        $sale = Sale::with(['items', 'customer'])->find($row->sale_id);
        if (! $sale) {
            return response()->json(['message' => 'Linked sale not found.'], 422);
        }

        $finance = $this->erp->gateForRequest($request)->moduleSettings('finance');

        $pendingCredit = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->whereIn('status', ['pending', 'failed'])
            ->where('response_payload->document_type', 'credit_note')
            ->where('response_payload->source', 'kra_invoice_credit')
            ->where('response_payload->source_kra_response_id', $row->id)
            ->orderByDesc('id')
            ->first();

        try {
            $credited = app(CreditNoteService::class)->fiscalCreditSaleOnly(
                $sale,
                $request->user(),
                $finance,
                $data['refund_reason_code'] ?? null,
                $row,
                $pendingCredit,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'credit' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            // KraDeviceFailure aborts with HTTP exceptions — rethrow those.
            throw $e;
        }

        if ($credited->status === 'pending') {
            return response()->json([
                'success' => true,
                'queued' => true,
                'message' => 'KRA could not be reached immediately. The credit note is queued and will retry automatically; the Centrix sale was not changed.',
                'kra_response' => $credited,
            ], 202);
        }

        return response()->json([
            'message' => 'KRA credit note submitted successfully. Centrix sale was not changed.',
            'kra_response' => $credited,
        ], 201);
    }
}