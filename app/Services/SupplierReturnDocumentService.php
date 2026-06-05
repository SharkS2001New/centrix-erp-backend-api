<?php

namespace App\Services;

use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnDocument;
use App\Models\User;
use App\Support\LpoStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierReturnDocumentService
{
    public function __construct(
        protected LpoInventoryService $inventory,
        protected LpoModuleService $lpoModule,
        protected SupplierBalanceService $supplierBalances,
    ) {}

    public function paginatedList(Request $request, ?User $user = null): array
    {
        $query = SupplierReturnDocument::query()
            ->from('supplier_return_documents as d')
            ->join('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.returned_by')
            ->leftJoin('users as ua', 'ua.id', '=', 'd.approved_by')
            ->select([
                'd.*',
                's.supplier_name',
                'u.full_name as returned_by_name',
                'ua.full_name as approved_by_name',
            ])
            ->selectSub(
                SupplierReturn::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('document_id', 'd.id'),
                'line_count',
            )
            ->orderByDesc('d.id');

        if ($request->filled('supplier_id')) {
            $query->where('d.supplier_id', (int) $request->input('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('d.status', (string) $request->input('status'));
        }
        if ($request->filled('source_type')) {
            $query->where('d.source_type', (string) $request->input('source_type'));
        }
        if ($request->filled('lpo_no')) {
            $query->where('d.lpo_no', (int) $request->input('lpo_no'));
        }
        if ($from = $request->input('date_from')) {
            $query->whereDate('d.created_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('d.created_at', '<=', $to);
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $page = $query->paginate($perPage);

        $docIds = collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $linesByDoc = $this->linesGroupedByDocumentIds($docIds);

        return [
            'data' => collect($page->items())
                ->map(fn ($row) => $this->mapDocumentSummary($row, $user, $linesByDoc[(int) $row->id] ?? []))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    public function show(int $id, ?User $user = null): array
    {
        $doc = $this->findDocumentOrFail($id);
        $lines = $this->loadLinesForDocument($doc);

        return $this->mapDocumentDetail($doc, $lines, $user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): array
    {
        $lines = $data['lines'] ?? [];
        if (! is_array($lines) || count($lines) < 1) {
            throw new InvalidArgumentException('Add at least one product line to the return.');
        }

        $reasonScope = $this->normalizeReasonScope($data['reason_scope'] ?? 'order');
        $notes = $this->resolveDocumentNotes($data, $lines, $reasonScope);
        $this->assertUniqueProductsInLines($lines);

        $sourceType = (string) ($data['source_type'] ?? 'manual');
        $supplierId = (int) $data['supplier_id'];
        $branchId = (int) $data['branch_id'];
        $lpoNo = isset($data['lpo_no']) ? (int) $data['lpo_no'] : null;

        Supplier::query()->whereNull('deleted_at')->where('id', $supplierId)->firstOrFail();

        if ($sourceType === 'lpo') {
            if (! $lpoNo) {
                throw new InvalidArgumentException('Select a purchase order for this return.');
            }
            $lpo = $this->assertLpoReturnable($lpoNo, $supplierId);
        } else {
            $lpo = null;
            $lpoNo = null;
        }

        $invoiceNo = trim((string) ($data['supplier_invoice_no'] ?? '')) ?: null;

        return DB::transaction(function () use ($data, $lines, $notes, $reasonScope, $sourceType, $supplierId, $branchId, $lpoNo, $lpo, $invoiceNo, $user) {
            $doc = SupplierReturnDocument::create([
                'supplier_id' => $supplierId,
                'branch_id' => $branchId,
                'source_type' => $sourceType,
                'lpo_no' => $lpoNo,
                'supplier_invoice_no' => $invoiceNo,
                'status' => 'pending_approval',
                'notes' => $notes,
                'reason_scope' => $reasonScope,
                'returned_by' => (int) $user->id,
            ]);

            foreach ($lines as $line) {
                $this->createLineForDocument($doc, $line, $lpo, $user, false, $reasonScope, $notes);
            }

            if ($lpoNo) {
                $this->lpoModule->syncReturnStatus($lpoNo);
            }

            return $this->show((int) $doc->id, $user);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data, User $user): array
    {
        $doc = $this->findDocumentOrFail($id);
        $wasApproved = $doc->status === 'approved';

        if ($wasApproved) {
            if (! $this->userCanApprove($user)) {
                throw new InvalidArgumentException('Only a senior user (admin) can edit an approved return.');
            }
        } else {
            $this->assertPending($doc);
        }

        $lines = $data['lines'] ?? null;
        $reasonScope = array_key_exists('reason_scope', $data)
            ? $this->normalizeReasonScope($data['reason_scope'])
            : ($doc->reason_scope ?? 'order');

        if (is_array($lines)) {
            $notes = $this->resolveDocumentNotes($data, $lines, $reasonScope);
            $this->assertUniqueProductsInLines($lines);
        } else {
            $notes = array_key_exists('notes', $data) || array_key_exists('return_reason', $data) || array_key_exists('reason', $data)
                ? trim((string) ($data['notes'] ?? $data['return_reason'] ?? $data['reason'] ?? ''))
                : $doc->notes;
            if ($reasonScope === 'order' && strlen($notes) < 3) {
                throw new InvalidArgumentException('Enter a return reason for this order.');
            }
        }

        $invoiceNo = array_key_exists('supplier_invoice_no', $data)
            ? (trim((string) ($data['supplier_invoice_no'] ?? '')) ?: null)
            : $doc->supplier_invoice_no;

        return DB::transaction(function () use ($doc, $lines, $notes, $reasonScope, $invoiceNo, $user, $wasApproved) {
            if ($wasApproved) {
                $this->reverseStockForDocument($doc, $user);
            }

            $doc->update([
                'notes' => $notes,
                'reason_scope' => $reasonScope,
                'supplier_invoice_no' => $invoiceNo,
            ]);

            if (is_array($lines)) {
                if (count($lines) < 1) {
                    throw new InvalidArgumentException('Add at least one product line to the return.');
                }
                SupplierReturn::query()->where('document_id', $doc->id)->delete();
                $lpo = $doc->source_type === 'lpo' && $doc->lpo_no
                    ? $this->assertLpoReturnable((int) $doc->lpo_no, (int) $doc->supplier_id)
                    : null;
                foreach ($lines as $line) {
                    $this->createLineForDocument(
                        $doc,
                        $line,
                        $lpo,
                        $user,
                        $wasApproved,
                        $reasonScope,
                        $notes,
                    );
                }
            }

            if ($wasApproved) {
                $lpo = $doc->source_type === 'lpo' && $doc->lpo_no
                    ? LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $doc->lpo_no)->first()
                    : null;
                if ($lpo) {
                    $this->lpoModule->syncClearedStatus((int) $lpo->lpo_no);
                }
                $this->supplierBalances->recalculate((int) $doc->supplier_id);
            }

            if ($doc->source_type === 'lpo' && $doc->lpo_no) {
                $this->lpoModule->syncReturnStatus((int) $doc->lpo_no);
            }

            return $this->show((int) $doc->id, $user);
        });
    }

    public function delete(int $id, ?User $user = null): void
    {
        $doc = $this->findDocumentOrFail($id);

        if ($doc->status === 'approved') {
            if (! $user || ! $this->userCanApprove($user)) {
                throw new InvalidArgumentException('Only a senior user (admin) can delete an approved return.');
            }
        } elseif ($doc->status === 'rejected') {
            if (! $user || ! $this->userCanApprove($user)) {
                throw new InvalidArgumentException('Only a senior user (admin) can delete this return.');
            }
        } elseif ($doc->status === 'pending_approval') {
            // Anyone who can access the document may delete while pending.
        } else {
            throw new InvalidArgumentException('This return cannot be deleted.');
        }

        $lpoNo = $doc->source_type === 'lpo' ? (int) $doc->lpo_no : null;
        $supplierId = (int) $doc->supplier_id;

        DB::transaction(function () use ($doc, $user, $lpoNo, $supplierId) {
            if ($doc->status === 'approved' && $user) {
                $this->reverseStockForDocument($doc, $user);
            }

            SupplierReturn::query()->where('document_id', $doc->id)->delete();
            $doc->delete();

            if ($lpoNo) {
                $this->lpoModule->syncClearedStatus($lpoNo);
                $this->lpoModule->syncReturnStatus($lpoNo);
            }
            $this->supplierBalances->recalculate($supplierId);
        });
    }

    public function approve(int $id, User $user): array
    {
        $doc = $this->findDocumentOrFail($id);
        $this->assertPending($doc);

        if (! $this->userCanApprove($user)) {
            throw new InvalidArgumentException('Only a senior user (admin) can approve supplier returns.');
        }

        $lpo = $doc->source_type === 'lpo' && $doc->lpo_no
            ? LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $doc->lpo_no)->firstOrFail()
            : null;

        DB::transaction(function () use ($doc, $lpo, $user) {
            $lines = SupplierReturn::query()->where('document_id', $doc->id)->get();
            foreach ($lines as $line) {
                $this->applyStockForLine($line, $lpo, $user);
            }

            $doc->update([
                'status' => 'approved',
                'approved_by' => (int) $user->id,
                'approved_at' => now(),
            ]);

            if ($lpo) {
                $this->lpoModule->syncClearedStatus((int) $lpo->lpo_no);
                $this->lpoModule->syncReturnStatus((int) $lpo->lpo_no);
            }
            $this->supplierBalances->recalculate((int) $doc->supplier_id);
        });

        return $this->show($id, $user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reject(int $id, array $data, User $user): array
    {
        $doc = $this->findDocumentOrFail($id);

        if (! in_array($doc->status, ['pending_approval', 'approved'], true)) {
            throw new InvalidArgumentException('Only pending or approved returns can be rejected.');
        }

        if (! $this->userCanApprove($user)) {
            throw new InvalidArgumentException('Only a senior user (admin) can reject supplier returns.');
        }

        $reason = trim((string) ($data['rejection_reason'] ?? $data['reason'] ?? ''));
        if (strlen($reason) < 3) {
            throw new InvalidArgumentException('Enter a reason for rejection.');
        }

        $wasApproved = $doc->status === 'approved';
        $lpoNo = $doc->source_type === 'lpo' ? (int) $doc->lpo_no : null;

        DB::transaction(function () use ($doc, $reason, $user, $wasApproved, $lpoNo) {
            if ($wasApproved) {
                $this->reverseStockForDocument($doc, $user);
            }

            $doc->update([
                'status' => 'rejected',
                'rejected_by' => (int) $user->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            if ($wasApproved && $lpoNo) {
                $this->lpoModule->syncClearedStatus($lpoNo);
            }
            if ($lpoNo) {
                $this->lpoModule->syncReturnStatus($lpoNo);
            }
            if ($wasApproved) {
                $this->supplierBalances->recalculate((int) $doc->supplier_id);
            }
        });

        return $this->show($id, $user);
    }

    public function listForLpo(int $lpoNo): array
    {
        return SupplierReturnDocument::query()
            ->where('lpo_no', $lpoNo)
            ->orderByDesc('id')
            ->get()
            ->map(function (SupplierReturnDocument $doc) {
                $lines = $this->loadLinesForDocument($doc);

                return $this->mapDocumentDetail($doc, $lines, null);
            })
            ->values()
            ->all();
    }

    protected function findDocumentOrFail(int $id): SupplierReturnDocument
    {
        return SupplierReturnDocument::query()->findOrFail($id);
    }

    protected function assertPending(SupplierReturnDocument $doc): void
    {
        if ($doc->status !== 'pending_approval') {
            throw new InvalidArgumentException('Only returns awaiting approval can be changed.');
        }
    }

    protected function userCanApprove(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    protected function assertLpoReturnable(int $lpoNo, int $supplierId): LpoMst
    {
        $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->firstOrFail();

        if ((int) $lpo->supplier_id !== $supplierId) {
            throw new InvalidArgumentException('This LPO belongs to a different supplier.');
        }

        if ((int) $lpo->lpo_status_code < LpoStatus::AWAITING_RECEIVE) {
            throw new InvalidArgumentException(
                'Supplier returns are only allowed after the LPO has been sent to the supplier.',
            );
        }

        if ((int) $lpo->lpo_status_code === LpoStatus::CANCELLED_RETURNED) {
            throw new InvalidArgumentException(
                'This LPO was cancelled because all items were returned to the supplier.',
            );
        }

        return $lpo;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function createLineForDocument(
        SupplierReturnDocument $doc,
        array $line,
        ?LpoMst $lpo,
        User $user,
        bool $applyStock,
        ?string $reasonScope = null,
        ?string $documentNotes = null,
    ): SupplierReturn {
        $reasonScope ??= $doc->reason_scope ?? 'order';
        $documentNotes ??= $doc->notes;
        $productCode = (string) ($line['product_code'] ?? '');
        $qty = (float) ($line['quantity'] ?? 0);
        if (! $productCode || $qty <= 0) {
            throw new InvalidArgumentException('Each line needs a product and quantity greater than zero.');
        }

        $lineReason = trim((string) ($line['reason'] ?? ''));
        $reason = $reasonScope === 'per_product' && strlen($lineReason) >= 3
            ? $lineReason
            : ($documentNotes ?: $doc->notes);

        if ($doc->source_type === 'lpo' && $lpo) {
            $txn = LpoTxn::query()
                ->where('lpo_no', $lpo->lpo_no)
                ->where('product_code', $productCode)
                ->first();
            if (! $txn) {
                throw new InvalidArgumentException("Product {$productCode} is not on this LPO.");
            }
            $maxReturn = $this->lpoModule->maxReturnQty($txn);
            if ($qty > $maxReturn + 0.0001) {
                throw new InvalidArgumentException(
                    "Return quantity for {$productCode} cannot exceed {$maxReturn} on this LPO line.",
                );
            }
            $uom = $line['uom_label'] ?? $txn->uom;
            $unitCost = (float) ($txn->cost_price ?? 0);
            $stockLocation = $this->resolveLineStockLocation($line, $txn, true);
        } else {
            $product = Product::query()->where('product_code', $productCode)->first();
            if (! $product) {
                throw new InvalidArgumentException('Product not found.');
            }
            $uom = $line['uom_label'] ?? null;
            $unitCost = (float) ($product->last_cost_price ?? 0);
            $txn = null;
            $stockLocation = $this->resolveLineStockLocation($line, null, false);
        }

        $return = SupplierReturn::create([
            'document_id' => $doc->id,
            'supplier_id' => (int) $doc->supplier_id,
            'branch_id' => (int) $doc->branch_id,
            'product_code' => $productCode,
            'quantity' => $qty,
            'package_type' => $line['package_type'] ?? 'partial',
            'uom_label' => $uom,
            'stock_location' => $stockLocation,
            'reason' => $reason,
            'reference_type' => $doc->source_type === 'lpo' ? 'lpo' : 'manual',
            'reference_id' => $doc->lpo_no,
            'returned_by' => (int) $user->id,
        ]);

        if ($applyStock) {
            $this->applyStockForLine($return, $lpo, $user, $txn, $unitCost);
        }

        return $return;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function resolveLineStockLocation(array $line, ?LpoTxn $txn, bool $isLpo): string
    {
        $requested = strtolower(trim((string) ($line['stock_location'] ?? 'store')));
        if ($requested === 'both') {
            throw new InvalidArgumentException('Return each product from a single location (Shop or Store).');
        }

        if ($isLpo && $txn) {
            return $this->lpoModule->resolveLpoReturnStockLocation($txn, $requested ?: null);
        }

        if (! in_array($requested, ['shop', 'store'], true)) {
            throw new InvalidArgumentException('Select Shop or Store for the return location.');
        }

        return $requested;
    }

    protected function applyStockForLine(
        SupplierReturn $line,
        ?LpoMst $lpo,
        User $user,
        ?LpoTxn $txn = null,
        ?float $unitCost = null,
    ): void {
        if ($line->reference_type === 'lpo' && $lpo) {
            $txn ??= LpoTxn::query()
                ->where('lpo_no', $lpo->lpo_no)
                ->where('product_code', $line->product_code)
                ->first();
            if (! $txn) {
                return;
            }
            $stockDeductQty = $this->lpoModule->stockDeductQtyForReturn($txn, (float) $line->quantity);
            $unitCost = (float) ($txn->cost_price ?? 0);
            if ($stockDeductQty <= 0) {
                return;
            }
            $this->inventory->adjustStock([
                'branch_id' => (int) $line->branch_id,
                'product_code' => $line->product_code,
                'stock_location' => $line->stock_location ?? 'store',
                'transaction_type' => 'SUPPLIER_RETURN',
                'reference_type' => 'supplier_return',
                'reference_id' => $line->id,
                'quantity_change' => -abs($stockDeductQty),
                'unit_cost' => $unitCost,
                'notes' => $line->reason,
                'created_by' => (int) $user->id,
            ]);

            return;
        }

        $product = Product::query()->where('product_code', $line->product_code)->first();
        $unitCost ??= (float) ($product->last_cost_price ?? 0);

        $this->inventory->adjustStock([
            'branch_id' => (int) $line->branch_id,
            'product_code' => $line->product_code,
            'stock_location' => $line->stock_location ?? 'store',
            'transaction_type' => 'SUPPLIER_RETURN',
            'reference_type' => 'supplier_return',
            'reference_id' => $line->id,
            'quantity_change' => -abs((float) $line->quantity),
            'unit_cost' => $unitCost,
            'notes' => $line->reason,
            'created_by' => (int) $user->id,
        ]);
    }

    protected function reverseStockForDocument(SupplierReturnDocument $doc, User $user): void
    {
        $lpo = $doc->source_type === 'lpo' && $doc->lpo_no
            ? LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $doc->lpo_no)->first()
            : null;

        $lines = SupplierReturn::query()->where('document_id', $doc->id)->get();
        foreach ($lines as $line) {
            $this->reverseStockForLine($line, $lpo, $user);
        }
    }

    protected function reverseStockForLine(
        SupplierReturn $line,
        ?LpoMst $lpo,
        User $user,
    ): void {
        if ($line->reference_type === 'lpo' && $lpo) {
            $txn = LpoTxn::query()
                ->where('lpo_no', $lpo->lpo_no)
                ->where('product_code', $line->product_code)
                ->first();
            if (! $txn) {
                return;
            }
            $stockRestoreQty = $this->lpoModule->stockDeductQtyForReturn($txn, (float) $line->quantity);
            $unitCost = (float) ($txn->cost_price ?? 0);
            if ($stockRestoreQty <= 0) {
                return;
            }
            $this->inventory->adjustStock([
                'branch_id' => (int) $line->branch_id,
                'product_code' => $line->product_code,
                'stock_location' => $line->stock_location ?? 'store',
                'transaction_type' => 'SUPPLIER_RETURN_REVERSAL',
                'reference_type' => 'supplier_return',
                'reference_id' => $line->id,
                'quantity_change' => abs($stockRestoreQty),
                'unit_cost' => $unitCost,
                'notes' => 'Reversal: '.($line->reason ?? ''),
                'created_by' => (int) $user->id,
            ]);

            return;
        }

        $product = Product::query()->where('product_code', $line->product_code)->first();
        $unitCost = (float) ($product->last_cost_price ?? 0);

        $this->inventory->adjustStock([
            'branch_id' => (int) $line->branch_id,
            'product_code' => $line->product_code,
            'stock_location' => $line->stock_location ?? 'store',
            'transaction_type' => 'SUPPLIER_RETURN_REVERSAL',
            'reference_type' => 'supplier_return',
            'reference_id' => $line->id,
            'quantity_change' => abs((float) $line->quantity),
            'unit_cost' => $unitCost,
            'notes' => 'Reversal: '.($line->reason ?? ''),
            'created_by' => (int) $user->id,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function loadLinesForDocument(SupplierReturnDocument $doc)
    {
        return SupplierReturn::query()
            ->from('supplier_returns as sr')
            ->join('products as p', 'p.product_code', '=', 'sr.product_code')
            ->where('sr.document_id', $doc->id)
            ->select(['sr.*', 'p.product_name'])
            ->orderBy('sr.id')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function mapDocumentSummary(object $row, ?User $user, array $lines = []): array
    {
        return [
            'id' => (int) $row->id,
            'supplier_return_no' => 'SR-'.(int) $row->id,
            'supplier_id' => (int) $row->supplier_id,
            'supplier_name' => $row->supplier_name ?? null,
            'branch_id' => (int) $row->branch_id,
            'source_type' => $row->source_type,
            'reference' => $this->documentReference($row),
            'lpo_no' => $row->lpo_no ? (int) $row->lpo_no : null,
            'supplier_invoice_no' => $row->supplier_invoice_no ?? null,
            'status' => $row->status,
            'status_label' => $this->statusLabel($row->status),
            'notes' => $row->notes,
            'return_reason' => $row->notes,
            'reason_scope' => $row->reason_scope ?? 'order',
            'line_count' => (int) ($row->line_count ?? count($lines)),
            'lines' => $lines,
            'returned_by_name' => $row->returned_by_name ?? null,
            'approved_by_name' => $row->approved_by_name ?? null,
            'rejection_reason' => $row->rejection_reason ?? null,
            'created_at' => $row->created_at
                ? \Carbon\Carbon::parse($row->created_at)->format('Y-m-d H:i')
                : null,
            ...$this->documentPermissions($row, $user),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     */
    protected function mapDocumentDetail(SupplierReturnDocument $doc, $lines, ?User $user): array
    {
        $supplier = Supplier::query()->find($doc->supplier_id);

        return [
            'id' => (int) $doc->id,
            'supplier_return_no' => 'SR-'.(int) $doc->id,
            'supplier_id' => (int) $doc->supplier_id,
            'supplier_name' => $supplier?->supplier_name,
            'branch_id' => (int) $doc->branch_id,
            'source_type' => $doc->source_type,
            'reference' => $this->documentReference($doc),
            'lpo_no' => $doc->lpo_no ? (int) $doc->lpo_no : null,
            'supplier_invoice_no' => $doc->supplier_invoice_no,
            'status' => $doc->status,
            'status_label' => $this->statusLabel($doc->status),
            'notes' => $doc->notes,
            'return_reason' => $doc->notes,
            'reason_scope' => $doc->reason_scope ?? 'order',
            'rejection_reason' => $doc->rejection_reason,
            'returned_by' => (int) $doc->returned_by,
            'approved_by' => $doc->approved_by ? (int) $doc->approved_by : null,
            'approved_at' => $doc->approved_at
                ? \Carbon\Carbon::parse($doc->approved_at)->format('Y-m-d H:i')
                : null,
            'rejected_at' => $doc->rejected_at
                ? \Carbon\Carbon::parse($doc->rejected_at)->format('Y-m-d H:i')
                : null,
            'created_at' => $doc->created_at
                ? \Carbon\Carbon::parse($doc->created_at)->format('Y-m-d H:i')
                : null,
            ...$this->documentPermissions($doc, $user),
            'lines' => $lines->map(fn ($l) => $this->mapLineRow($l))->values()->all(),
        ];
    }

    /**
     * @return array{can_edit: bool, can_delete: bool, can_approve: bool, can_reject: bool}
     */
    protected function documentPermissions(object $doc, ?User $user): array
    {
        $isAdmin = $user && $this->userCanApprove($user);
        $status = (string) ($doc->status ?? 'pending_approval');
        $pending = $status === 'pending_approval';
        $approved = $status === 'approved';
        $rejected = $status === 'rejected';

        return [
            'can_edit' => $pending || ($approved && $isAdmin),
            'can_delete' => $pending || (($approved || $rejected) && $isAdmin),
            'can_approve' => $pending && $isAdmin,
            'can_reject' => ($pending || $approved) && $isAdmin,
        ];
    }

    /**
     * @param  array<int, int>  $documentIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function linesGroupedByDocumentIds(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $rows = SupplierReturn::query()
            ->from('supplier_returns as sr')
            ->join('products as p', 'p.product_code', '=', 'sr.product_code')
            ->whereIn('sr.document_id', $documentIds)
            ->select(['sr.*', 'p.product_name'])
            ->orderBy('sr.document_id')
            ->orderBy('sr.id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $docId = (int) $row->document_id;
            $grouped[$docId] ??= [];
            $grouped[$docId][] = $this->mapLineRow($row);
        }

        return $grouped;
    }

    protected function mapLineRow(object $l): array
    {
        return [
            'id' => (int) $l->id,
            'product_code' => $l->product_code,
            'product_name' => $l->product_name ?? $l->product_code,
            'quantity' => (float) $l->quantity,
            'package_type' => $l->package_type,
            'package_type_label' => $this->packageTypeLabel($l->package_type, $l->uom_label),
            'uom_label' => $l->uom_label,
            'stock_location' => $l->stock_location,
            'reason' => $l->reason,
        ];
    }

    protected function packageTypeLabel(?string $type, ?string $uomLabel): string
    {
        $pack = $uomLabel ? trim($uomLabel) : 'package';

        return match ($type) {
            'full_package' => "Full {$pack}",
            'pieces' => 'Individual pieces (loose units)',
            'partial' => "Partial {$pack}",
            default => $type ?? '—',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function resolveDocumentNotes(array $data, array $lines, string $reasonScope): string
    {
        $notes = trim((string) ($data['notes'] ?? $data['return_reason'] ?? $data['reason'] ?? ''));

        if ($reasonScope === 'order') {
            if (strlen($notes) < 3) {
                throw new InvalidArgumentException('Enter a return reason for the whole order.');
            }

            return $notes;
        }

        foreach ($lines as $line) {
            if (strlen(trim((string) ($line['reason'] ?? ''))) < 3) {
                throw new InvalidArgumentException('Each product needs its own return reason (at least 3 characters).');
            }
        }

        if (strlen($notes) < 3) {
            $notes = collect($lines)
                ->map(fn ($l) => trim((string) ($l['reason'] ?? '')))
                ->filter(fn ($r) => strlen($r) >= 3)
                ->join('; ');
        }

        if (strlen($notes) < 3) {
            throw new InvalidArgumentException('Enter a return reason for this return.');
        }

        return $notes;
    }

    protected function normalizeReasonScope(mixed $scope): string
    {
        return in_array($scope, ['order', 'per_product'], true) ? $scope : 'order';
    }

    /**
     * One product per return line — single shop or store location only.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function assertUniqueProductsInLines(array $lines): void
    {
        $seen = [];
        foreach ($lines as $line) {
            $code = trim((string) ($line['product_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $loc = strtolower((string) ($line['stock_location'] ?? ''));
            if ($loc === 'both') {
                throw new InvalidArgumentException(
                    'Return each product from a single location (Shop or Store).',
                );
            }
            if (isset($seen[$code])) {
                throw new InvalidArgumentException(
                    "Product {$code} is listed more than once. Use one line per product.",
                );
            }
            $seen[$code] = true;
        }
    }

    protected function documentReference(object $row): string
    {
        if (($row->source_type ?? '') === 'lpo' && ! empty($row->lpo_no)) {
            return 'LPO '.(int) $row->lpo_no;
        }

        return 'Manual return';
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_approval' => 'Pending approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => $status,
        };
    }
}
