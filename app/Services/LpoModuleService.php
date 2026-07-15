<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\ActionRequest;
use App\Models\LpoMst;
use App\Models\LpoStatus;
use App\Models\LpoSupplierInvoice;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnDocument;
use App\Models\Uom;
use App\Models\User;
use App\Services\Notifications\ActionRequestService;
use App\Services\Purchasing\LpoWorkflowService;
use App\Services\Purchasing\SupplierReturnDocumentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LpoModuleService
{
    public const STATUS_AWAITING_RECEIVE = 3;

    public const STATUS_PARTIALLY_RECEIVED = 4;

    public const STATUS_FULLY_RECEIVED = 5;

    public const STATUS_CLEARED = 6;

    public const STATUS_CANCELLED_RETURNED = 7;

    /** Canonical labels when lpo_statuses rows are missing or outdated. */
    public const STATUS_LABELS = [
        0 => 'Awaiting check',
        1 => 'Awaiting approval',
        2 => 'Awaiting send',
        3 => 'Awaiting receive',
        4 => 'Partially received',
        5 => 'Fully received',
        6 => 'Cleared',
        7 => 'Cancelled / returned',
    ];

    public static function statusLabel(?int $statusCode): string
    {
        $code = (int) ($statusCode ?? 0);

        return self::STATUS_LABELS[$code] ?? "Status {$code}";
    }

    /**
     * Advance LPO header status from line received_qty (partial vs fully received).
     * Does not regress cleared / cancelled headers.
     */
    public function syncReceiveHeaderStatus(int $lpoNo): void
    {
        if ($lpoNo <= 0) {
            return;
        }

        $lpo = LpoMst::query()->lockForUpdate()->find($lpoNo);
        if (! $lpo || (int) ($lpo->cleared_flag ?? 0) === 1) {
            return;
        }

        $current = (int) ($lpo->lpo_status_code ?? 0);
        if ($current >= self::STATUS_CLEARED || $current === self::STATUS_CANCELLED_RETURNED) {
            return;
        }

        $lines = LpoTxn::query()->where('lpo_no', $lpoNo)->get();
        if ($lines->isEmpty()) {
            return;
        }

        $anyReceived = false;
        $allComplete = true;
        foreach ($lines as $txn) {
            $ordered = (float) ($txn->ordered_qty ?? 0);
            $received = (float) ($txn->received_qty ?? 0);
            $offer = (float) ($txn->offer_qty ?? max(0.0, $received - $ordered));
            $paidReceived = max(0.0, $received - $offer);
            if ($received > 0.0001) {
                $anyReceived = true;
            }
            if ($ordered > 0.0001 && $paidReceived + 0.0001 < $ordered) {
                $allComplete = false;
            }
        }

        if (! $anyReceived) {
            return;
        }

        $next = $allComplete
            ? self::STATUS_FULLY_RECEIVED
            : self::STATUS_PARTIALLY_RECEIVED;

        if ($next !== $current) {
            $lpo->update(['lpo_status_code' => $next]);
        }
    }

    public function formatPoNumber(int $lpoNo, $orderDate = null): string
    {
        $year = $orderDate
            ? (int) date('Y', strtotime((string) $orderDate))
            : (int) date('Y');

        return sprintf('LPO-%d-%04d', $year, $lpoNo);
    }

    /**
     * Resolve an LPO for an organization. Prefer primary key (lpo_no), then org-local
     * sequence (lpo_seq) so URLs that still use the display sequence keep working.
     */
    public function findForOrganization(int $lpoNoOrSeq, ?int $organizationId = null): LpoMst
    {
        $query = LpoMst::query()
            ->with('supplier')
            ->whereNull('deleted_at');

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $byPk = (clone $query)->where('lpo_no', $lpoNoOrSeq)->first();
        if ($byPk) {
            return $byPk;
        }

        if ($organizationId) {
            $bySeq = (clone $query)->where('lpo_seq', $lpoNoOrSeq)->first();
            if ($bySeq) {
                return $bySeq;
            }
        }

        throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(LpoMst::class, [$lpoNoOrSeq]);
    }

    public function mapListRow(LpoMst $lpo, ?int $organizationId = null, ?User $viewer = null): array
    {
        return $this->mapListRows(collect([$lpo]), $organizationId, $viewer)[0];
    }

    /**
     * @param  Collection<int, LpoMst>  $lpos
     * @return list<array<string, mixed>>
     */
    public function mapListRows(Collection $lpos, ?int $organizationId = null, ?User $viewer = null): array
    {
        if ($lpos->isEmpty()) {
            return [];
        }

        // Keep status 0 — Collection::filter() would drop it as "empty".
        $statusCodes = $lpos->pluck('lpo_status_code')
            ->map(fn ($code) => (int) ($code ?? 0))
            ->unique()
            ->values()
            ->all();
        $statuses = LpoStatus::query()
            ->whereIn('status_code', $statusCodes)
            ->get()
            ->keyBy(fn (LpoStatus $status) => (int) $status->status_code);

        $creatorIds = $lpos->pluck('created_by')->filter()->unique()->values()->all();
        $creators = $creatorIds === []
            ? collect()
            : User::query()->whereIn('id', $creatorIds)->get()->keyBy('id');

        $lpoNos = $lpos->pluck('lpo_no')->map(fn ($no) => (int) $no)->all();
        $paymentsByLpo = $this->paymentsTotalsByLpo($lpoNos);

        $supplierIds = $lpos->pluck('supplier_id')->filter()->unique()->values()->all();
        $suppliers = $supplierIds === []
            ? collect()
            : \App\Models\Supplier::query()->whereIn('id', $supplierIds)->get()->keyBy('id');

        $workflow = app(LpoWorkflowService::class);
        $pendingApprovals = $this->pendingApprovalRequestsForLpos($lpoNos, $organizationId);
        $lastRejections = $this->lastRejectedApprovalsForLpos($lpoNos, $organizationId);

        return $lpos->map(function (LpoMst $lpo) use (
            $statuses,
            $creators,
            $paymentsByLpo,
            $suppliers,
            $workflow,
            $organizationId,
            $pendingApprovals,
            $lastRejections,
            $viewer,
        ) {
            $statusCode = (int) ($lpo->lpo_status_code ?? 0);
            $status = $statuses->get($statusCode);
            $creator = $lpo->created_by ? $creators->get($lpo->created_by) : null;
            $orderDate = $lpo->created_at ?? $lpo->sent_at;
            $canEdit = $statusCode < self::STATUS_AWAITING_RECEIVE;
            $lpoNo = (int) $lpo->lpo_no;
            $paymentsTotal = (float) ($paymentsByLpo[$lpoNo] ?? 0);
            $netAmount = (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0);
            $supplier = $lpo->supplier ?? $suppliers->get($lpo->supplier_id);
            $pendingRequest = $pendingApprovals->get($lpoNo);
            $statusName = trim((string) ($status?->status_name ?? ''));
            if ($statusName === '') {
                $statusName = self::statusLabel($statusCode);
            }
            if ((int) ($lpo->cleared_flag ?? 0) === 1 && $statusCode >= self::STATUS_FULLY_RECEIVED) {
                $statusName = self::statusLabel(self::STATUS_CLEARED);
            }

            return [
                'lpo_no' => $lpoNo,
                'lpo_seq' => (int) ($lpo->lpo_seq ?? $lpoNo),
                'po_number' => $this->formatPoNumber((int) ($lpo->lpo_seq ?? $lpoNo), $orderDate),
                'supplier_id' => (int) $lpo->supplier_id,
                'supplier_name' => $supplier?->supplier_name,
                'reference_number' => $lpo->reference_number,
                'order_date' => $orderDate,
                'created_at' => $lpo->created_at,
                'due_date' => $lpo->due_date,
                'lpo_status_code' => $statusCode,
                'status_name' => $statusName,
                'cleared_flag' => (int) ($lpo->cleared_flag ?? 0),
                'total_amount' => (float) ($lpo->total_amount ?? 0),
                'vat_amount' => (float) ($lpo->vat_amount ?? 0),
                'net_amount' => (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0),
                'created_by_name' => $creator?->full_name ?? $creator?->username,
                'can_edit' => $canEdit,
                'can_delete' => $canEdit,
                'amount_paid' => round($paymentsTotal, 2),
                'balance_due' => round(max(0, $netAmount - $paymentsTotal), 2),
                'workflow_actions' => $workflow->workflowActions($lpo, $organizationId, $supplier),
                'approval_pending' => $pendingRequest !== null,
                'action_request' => $this->presentActionRequest($pendingRequest, $viewer),
                'approval_rejection' => $lastRejections[$lpoNo] ?? null,
            ];
        })->values()->all();
    }

    /** @param  list<int>  $lpoNos
     * @return Collection<int, ActionRequest>
     */
    protected function pendingApprovalRequestsForLpos(array $lpoNos, ?int $organizationId): Collection
    {
        if ($lpoNos === [] || ! $organizationId) {
            return collect();
        }

        return ActionRequest::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'lpo_approval')
            ->where('reference_type', 'lpo_mst')
            ->whereIn('reference_id', $lpoNos)
            ->where('status', 'pending')
            ->get()
            ->keyBy(fn (ActionRequest $request) => (int) $request->reference_id);
    }

    /** @param  list<int>  $lpoNos
     * @return array<int, array<string, mixed>>
     */
    protected function lastRejectedApprovalsForLpos(array $lpoNos, ?int $organizationId): array
    {
        if ($lpoNos === [] || ! $organizationId) {
            return [];
        }

        $requests = ActionRequest::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'lpo_approval')
            ->where('reference_type', 'lpo_mst')
            ->whereIn('reference_id', $lpoNos)
            ->where('status', 'rejected')
            ->orderByDesc('resolved_at')
            ->get()
            ->unique(fn (ActionRequest $request) => (int) $request->reference_id);

        $out = [];
        foreach ($requests as $request) {
            $lpoNo = (int) $request->reference_id;
            $comment = ApprovalAction::query()
                ->where('action_request_id', $request->id)
                ->where('action', 'rejected')
                ->orderByDesc('id')
                ->value('comment');

            $out[$lpoNo] = [
                'rejected' => true,
                'reason' => trim((string) ($comment ?? '')),
                'rejected_at' => $request->resolved_at?->toIso8601String(),
                'po_number' => (string) (($request->payload ?? [])['po_number'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function presentActionRequest(?ActionRequest $request, ?User $viewer): ?array
    {
        if ($request === null || $viewer === null) {
            return null;
        }

        return app(ActionRequestService::class)->presentForViewer($request, $viewer);
    }

    /** @param  list<int>  $lpoNos
     * @return array<int, true>
     */
    protected function pendingApprovalLpoNos(array $lpoNos, ?int $organizationId): array
    {
        if ($lpoNos === [] || ! $organizationId) {
            return [];
        }

        return ActionRequest::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'lpo_approval')
            ->where('reference_type', 'lpo_mst')
            ->whereIn('reference_id', $lpoNos)
            ->where('status', 'pending')
            ->pluck('reference_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** @param  list<int>  $lpoNos */
    protected function paymentsTotalsByLpo(array $lpoNos): array
    {
        if ($lpoNos === [] || ! Schema::hasTable('supplier_payments')) {
            return [];
        }

        return DB::table('supplier_payments')
            ->whereIn('lpo_no', $lpoNos)
            ->groupBy('lpo_no')
            ->selectRaw('lpo_no, COALESCE(SUM(amount_paid), 0) AS total')
            ->pluck('total', 'lpo_no')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    public function summary(int $lpoNo, ?int $organizationId = null, ?User $viewer = null): array
    {
        $lpo = $this->findForOrganization($lpoNo, $organizationId);

        $settings = \App\Services\Purchasing\ProcurementSettingsResolver::forOrganizationId($organizationId);
        $defaultReceiveLocation = $settings['default_receive_location'] ?? 'store';

        $status = LpoStatus::query()->find($lpo->lpo_status_code);
        $statusCode = (int) ($lpo->lpo_status_code ?? 0);
        $statusName = trim((string) ($status?->status_name ?? ''));
        if ($statusName === '') {
            $statusName = self::statusLabel($statusCode);
        }
        if ((int) ($lpo->cleared_flag ?? 0) === 1 && $statusCode >= self::STATUS_FULLY_RECEIVED) {
            $statusName = self::statusLabel(self::STATUS_CLEARED);
        }
        $creator = $lpo->created_by ? User::query()->find($lpo->created_by) : null;

        $lpoNo = (int) $lpo->lpo_no;
        $lines = LpoTxn::query()->where('lpo_no', $lpoNo)->orderBy('id')->get();
        $products = Product::query()
            ->with('unit')
            ->whereIn('product_code', $lines->pluck('product_code')->filter()->unique())
            ->get()
            ->keyBy('product_code');

        $returnedByProduct = $this->returnedQtyByProduct($lpoNo, (int) $lpo->supplier_id, $lines);
        $lineRows = $lines->map(fn (LpoTxn $txn) => $this->mapLine($txn, $products, $returnedByProduct, $defaultReceiveLocation));

        $paymentsTotal = $this->paymentsTotal($lpoNo);
        $receivedPayable = $this->receivedPayableTotal($lineRows);
        $returnCredit = $this->returnCreditTotal($lineRows);
        $netAmount = (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0);
        $payableBalance = max(0, $receivedPayable - $paymentsTotal);

        $itemsFullyReturned = $lineRows->isNotEmpty()
            && $lineRows->every(fn (array $line) => ($line['receive_status'] ?? '') === 'fully_returned');

        $pendingRequest = ActionRequest::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'lpo_approval')
            ->where('reference_type', 'lpo_mst')
            ->where('reference_id', $lpoNo)
            ->where('status', 'pending')
            ->first();

        $lastRejections = $this->lastRejectedApprovalsForLpos([$lpoNo], $organizationId);

        $supplierInvoices = $this->supplierInvoices($lpoNo);
        $receiveStaff = $this->receiveStaffForLpo(
            $lpo,
            $lineRows,
            $supplierInvoices,
            $organizationId,
        );

        $lpoPayload = [
            'lpo_no' => (int) $lpo->lpo_no,
            'lpo_seq' => (int) ($lpo->lpo_seq ?? $lpo->lpo_no),
            'po_number' => $this->formatPoNumber((int) ($lpo->lpo_seq ?? $lpo->lpo_no), $lpo->created_at ?? $lpo->sent_at),
            'supplier_id' => (int) $lpo->supplier_id,
            'supplier_name' => $lpo->supplier?->supplier_name,
            'reference_number' => $lpo->reference_number,
            'due_date' => $lpo->due_date,
            'delivery_address' => $lpo->delivery_address,
            'terms' => $lpo->terms,
            'instructions' => $lpo->instructions,
            'lpo_status_code' => $statusCode,
            'status_name' => $statusName,
            'cleared_flag' => (int) ($lpo->cleared_flag ?? 0),
            'total_amount' => (float) ($lpo->total_amount ?? 0),
            'vat_amount' => (float) ($lpo->vat_amount ?? 0),
            'net_amount' => $netAmount,
            'subtotal' => max(0, $netAmount - (float) ($lpo->vat_amount ?? 0)),
            'order_date' => $lpo->created_at ?? $lpo->sent_at,
            'created_at' => $lpo->created_at,
            'sent_at' => $lpo->sent_at,
            'created_by' => $lpo->created_by,
            'created_by_name' => $creator?->full_name ?? $creator?->username,
            'supplier_invoice_no' => $lpo->supplier_invoice_no,
            'can_edit' => (int) ($lpo->lpo_status_code ?? 0) < self::STATUS_AWAITING_RECEIVE,
            'can_delete' => (int) ($lpo->lpo_status_code ?? 0) < self::STATUS_AWAITING_RECEIVE,
            'can_pay' => $receivedPayable > 0,
            'can_receive' => ! $itemsFullyReturned
                && (int) ($lpo->lpo_status_code ?? 0) >= 2
                && (int) ($lpo->lpo_status_code ?? 0) < 5,
            'can_create_return' => ! $itemsFullyReturned
                && (int) ($lpo->lpo_status_code ?? 0) >= self::STATUS_AWAITING_RECEIVE
                && $lineRows->contains(fn (array $line) => (float) ($line['max_return_qty'] ?? 0) > 0),
            'payment_status' => $this->paymentStatus($paymentsTotal, $receivedPayable),
            'received_payable_total' => round($receivedPayable, 2),
            'return_credit_total' => round($returnCredit, 2),
            'adjusted_net_amount' => round(max(0, $netAmount - $returnCredit), 2),
            'items_fully_returned_to_supplier' => $itemsFullyReturned,
            'amount_paid' => round($paymentsTotal, 2),
            'balance_due' => round($payableBalance, 2),
            'workflow_actions' => app(\App\Services\Purchasing\LpoWorkflowService::class)
                ->workflowActions($lpo, $organizationId),
            'approval_pending' => $pendingRequest !== null,
            'action_request' => $this->presentActionRequest($pendingRequest, $viewer),
            'approval_rejection' => $lastRejections[$lpoNo] ?? null,
            'supplier_email' => $lpo->supplier?->email,
            'supplier_phone' => $lpo->supplier?->phone ?? $lpo->supplier?->alternate_phone,
            'default_receive_location' => $defaultReceiveLocation,
            'received_by' => $receiveStaff['user_ids'][0] ?? null,
            'received_by_name' => $receiveStaff['display_name'],
            'received_by_names' => $receiveStaff['names'],
            'received_by_user_ids' => $receiveStaff['user_ids'],
        ];

        return [
            'lpo' => $lpoPayload,
            'lines' => $lineRows->values()->all(),
            'supplier_invoices' => $supplierInvoices,
            'supplier_returns' => $this->supplierReturns($lpoNo, (int) $lpo->supplier_id, $lines),
            'payments_total' => round($paymentsTotal, 2),
            'balance_due' => round($payableBalance, 2),
        ];
    }

    /**
     * Staff who posted stock receipts for this LPO (via matching supplier invoice # / products).
     *
     * @param  Collection<int, array<string, mixed>>  $lineRows
     * @param  list<array<string, mixed>>  $supplierInvoices
     * @return array{user_ids: list<int>, names: list<string>, display_name: ?string}
     */
    protected function receiveStaffForLpo(
        LpoMst $lpo,
        Collection $lineRows,
        array $supplierInvoices,
        ?int $organizationId,
    ): array {
        $empty = ['user_ids' => [], 'names' => [], 'display_name' => null];
        $productCodes = $lineRows->pluck('product_code')->filter()->unique()->values()->all();
        if ($productCodes === []) {
            return $empty;
        }

        $invoiceNumbers = collect($supplierInvoices)
            ->pluck('supplier_invoice_number')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = StockReceipt::query()
            ->with('receiver:id,full_name,username')
            ->whereIn('product_code', $productCodes)
            ->whereNotNull('received_by');

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($invoiceNumbers !== []) {
            $query->whereIn('invoice_number', $invoiceNumbers);
        } else {
            // No supplier invoice attached yet — bound to receipts after this LPO was sent/created.
            $since = $lpo->sent_at ?? $lpo->created_at;
            if ($since) {
                $query->where('created_at', '>=', $since);
            }
        }

        $userIds = $query->orderBy('created_at')
            ->pluck('received_by')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Fallback: invoice # on the receipt may differ from the attached supplier invoice document.
        if ($userIds === [] && $invoiceNumbers !== []) {
            $since = $lpo->sent_at ?? $lpo->created_at;
            $fallback = StockReceipt::query()
                ->whereIn('product_code', $productCodes)
                ->whereNotNull('received_by');
            if ($organizationId) {
                $fallback->where('organization_id', $organizationId);
            }
            if ($since) {
                $fallback->where('created_at', '>=', $since);
            }
            $userIds = $fallback->orderBy('created_at')
                ->pluck('received_by')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if ($userIds === []) {
            return $empty;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'full_name', 'username'])
            ->keyBy('id');

        $names = [];
        foreach ($userIds as $id) {
            $user = $users->get($id);
            if (! $user) {
                continue;
            }
            $label = trim((string) ($user->full_name ?? ''));
            $names[] = $label !== '' ? $label : (string) $user->username;
        }
        $names = array_values(array_unique(array_filter($names)));

        return [
            'user_ids' => $userIds,
            'names' => $names,
            'display_name' => $names === [] ? null : implode(', ', $names),
        ];
    }

    protected function mapLine(LpoTxn $txn, Collection $products, array $returnedByProduct, string $defaultReceiveLocation = 'store'): array
    {
        $product = $products->get($txn->product_code);
        $uom = $product?->unit;
        $ordered = (float) ($txn->ordered_qty ?? 0);
        $received = (float) ($txn->received_qty ?? 0);
        $returned = (float) ($returnedByProduct[$txn->product_code] ?? 0);
        $offer = (float) ($txn->offer_qty ?? max(0, $received - $ordered));
        $openReturn = max(0, min($received - $offer, $ordered) - $returned);
        $remainingQty = max(0, $ordered - max(0, $received - $offer) - $returned);

        return [
            'id' => (int) $txn->id,
            'lpo_no' => (int) $txn->lpo_no,
            'product_code' => $txn->product_code,
            'product_name' => $product?->product_name ?? $txn->product_code,
            'ordered_qty' => $ordered,
            'received_qty' => $received,
            'offer_qty' => $offer,
            'remaining_qty' => $remainingQty,
            'returned_qty' => $returned,
            'committed_return_qty' => $returned,
            'max_return_qty' => $openReturn,
            'cost_price' => (float) ($txn->cost_price ?? 0),
            'line_total' => round($ordered * (float) ($txn->cost_price ?? 0), 2),
            'unit_id' => $product?->unit_id,
            'uom' => $txn->uom ?: $this->packageNameFromUom($uom),
            'packaging_label' => $this->formatPackagingLabel($uom),
            'package_name' => $this->packageNameFromUom($uom),
            'measure_unit' => $this->measureUnitFromUom($uom),
            'conversion_factor' => (float) ($uom?->conversion_factor ?? 1),
            'vat_rate' => (float) ($product?->vat?->vat_percentage ?? 0),
            'receive_status' => $this->lineReceiveStatus($ordered, $received, $returned),
            'received_qty_by_location' => [
                'shop' => $defaultReceiveLocation === 'shop' ? $received : 0,
                'store' => $defaultReceiveLocation === 'store' ? $received : 0,
            ],
            'received_location_options' => [$defaultReceiveLocation],
            'received_stock_location' => $received > 0 ? $defaultReceiveLocation : null,
            'default_receive_location' => $defaultReceiveLocation,
        ];
    }

    protected function lineReceiveStatus(float $ordered, float $received, float $returned): string
    {
        if ($ordered > 0 && $returned + 0.0001 >= $ordered) {
            return 'fully_returned';
        }
        if ($received + 0.0001 >= $ordered && $ordered > 0) {
            return 'complete';
        }
        if ($received > 0) {
            return 'partial';
        }

        return 'open';
    }

    protected function returnedQtyByProduct(int $lpoNo, int $supplierId, Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        if (Schema::hasTable('supplier_return_documents')) {
            return app(SupplierReturnDocumentService::class)->returnedQtyByProductForLpo($lpoNo);
        }

        $productCodes = $lines->pluck('product_code')->filter()->unique()->values();

        $query = SupplierReturn::query()->where('supplier_id', $supplierId);

        $query->where(function ($inner) use ($lpoNo, $productCodes) {
            $inner->where(function ($q) use ($lpoNo) {
                $q->where('reference_type', 'lpo')->where('reference_id', $lpoNo);
            })->orWhere(function ($q) use ($lpoNo) {
                $q->where('reference_type', 'lpo_no')->where('reference_id', $lpoNo);
            })->orWhereIn('product_code', $productCodes);
        });

        return $query
            ->select('product_code', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_code')
            ->pluck('qty', 'product_code')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    protected function supplierInvoices(int $lpoNo): array
    {
        return LpoSupplierInvoice::query()
            ->where('lpo_no', $lpoNo)
            ->orderByDesc('id')
            ->get()
            ->map(fn (LpoSupplierInvoice $inv) => [
                'id' => (int) $inv->id,
                'lpo_no' => (int) $inv->lpo_no,
                'supplier_id' => (int) $inv->supplier_id,
                'supplier_invoice_number' => $inv->supplier_invoice_number,
                'number' => $inv->supplier_invoice_number,
                'invoice_date' => $inv->invoice_date,
                'invoice_amount' => (float) ($inv->invoice_amount ?? 0),
                'has_document' => filled($inv->file_path),
                'file_name' => $inv->file_name,
                'received_at' => $inv->created_at,
            ])
            ->values()
            ->all();
    }

    protected function supplierReturns(int $lpoNo, int $supplierId, Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        if (Schema::hasTable('supplier_return_documents')) {
            return SupplierReturnDocument::query()
                ->with('lines')
                ->where('lpo_no', $lpoNo)
                ->where('supplier_id', $supplierId)
                ->orderByDesc('id')
                ->get()
                ->map(fn (SupplierReturnDocument $doc) => [
                    'id' => (int) $doc->id,
                    'status' => $doc->status === 'pending_approval' ? 'pending_approval' : $doc->status,
                    'status_label' => match ($doc->status) {
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default => 'Pending approval',
                    },
                    'return_reason' => $doc->return_reason,
                    'notes' => $doc->notes,
                    'created_at' => $doc->created_at?->toDateTimeString(),
                    'lines' => $doc->lines->map(fn ($line) => [
                        'product_code' => $line->product_code,
                        'quantity' => (float) $line->quantity,
                    ])->values()->all(),
                ])
                ->values()
                ->all();
        }

        $productCodes = $lines->pluck('product_code')->filter()->unique();

        $rows = SupplierReturn::query()
            ->where('supplier_id', $supplierId)
            ->where(function ($q) use ($lpoNo, $productCodes) {
                $q->where(function ($inner) use ($lpoNo) {
                    $inner->where('reference_type', 'lpo')->where('reference_id', $lpoNo);
                })->orWhere(function ($inner) use ($lpoNo) {
                    $inner->where('reference_type', 'lpo_no')->where('reference_id', $lpoNo);
                })->orWhereIn('product_code', $productCodes);
            })
            ->orderByDesc('created_at')
            ->get();

        return $rows
            ->groupBy(fn (SupplierReturn $row) => ($row->created_at?->format('Y-m-d H:i') ?? 'unknown') . ':' . $row->returned_by)
            ->map(function (Collection $group) {
                /** @var SupplierReturn $first */
                $first = $group->first();

                return [
                    'id' => (int) $first->id,
                    'status' => 'approved',
                    'status_label' => 'Approved',
                    'return_reason' => $first->reason,
                    'notes' => $first->reason,
                    'created_at' => $first->created_at?->toDateTimeString(),
                    'lines' => $group->map(fn (SupplierReturn $row) => [
                        'product_code' => $row->product_code,
                        'quantity' => (float) $row->quantity,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    protected function paymentsTotal(int $lpoNo): float
    {
        if (! Schema::hasTable('supplier_payments')) {
            return 0.0;
        }

        return (float) DB::table('supplier_payments')
            ->where('lpo_no', $lpoNo)
            ->sum('amount_paid');
    }

    protected function receivedPayableTotal(Collection $lineRows): float
    {
        return $lineRows->sum(function (array $line) {
            $received = (float) ($line['received_qty'] ?? 0);
            $offer = (float) ($line['offer_qty'] ?? 0);
            $cost = (float) ($line['cost_price'] ?? 0);
            $payableQty = max(0, $received - $offer);

            return $payableQty * $cost;
        });
    }

    protected function returnCreditTotal(Collection $lineRows): float
    {
        return $lineRows->sum(function (array $line) {
            $returned = (float) ($line['returned_qty'] ?? 0);
            $cost = (float) ($line['cost_price'] ?? 0);

            return $returned * $cost;
        });
    }

    protected function paymentStatus(float $paid, float $payable): string
    {
        if ($payable <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.0001 >= $payable) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }

        return 'unpaid';
    }

    protected function packageNameFromUom(?Uom $uom): string
    {
        if (! $uom) {
            return 'package';
        }

        return trim($uom->full_name ?: $uom->uom_type ?: 'package');
    }

    protected function measureUnitFromUom(?Uom $uom): string
    {
        if (! $uom) {
            return 'units';
        }

        $type = strtolower(trim((string) $uom->uom_type));

        return match ($type) {
            'piece', 'pcs' => 'pieces',
            default => $type ?: 'units',
        };
    }

    protected function formatPackagingLabel(?Uom $uom): ?string
    {
        if (! $uom) {
            return null;
        }

        $factor = (float) ($uom->conversion_factor ?? 1);
        $package = $this->packageNameFromUom($uom);
        $measure = $this->measureUnitFromUom($uom);

        if ($factor > 1) {
            return sprintf('%g %s per %s', $factor, $measure, $package);
        }

        return $package;
    }
}
