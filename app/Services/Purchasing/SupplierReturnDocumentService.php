<?php

namespace App\Services\Purchasing;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\InventoryTransaction;
use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnDocument;
use App\Models\SupplierReturnDocumentLine;
use App\Models\User;
use App\Services\Accounting\InventoryMovementJournalService;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Erp\ErpContext;
use App\Services\Returns\ReturnProofService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierReturnDocumentService
{
    use HandlesInventory;

    public function __construct(
        protected UserAccessService $access,
        protected UserPermissionService $permissions,
        protected ReturnProofService $proofService,
    ) {}

    /** @param  array<string, mixed>  $filters */
    public function listForUser(User $user, array $filters = []): Collection
    {
        $query = SupplierReturnDocument::query()
            ->with(['lines', 'supplier', 'returnedByUser'])
            ->where('organization_id', $user->organization_id);

        $this->access->scopeBranchIfLimited($query, $user);

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query
            ->orderByDesc('id')
            ->limit(min((int) ($filters['per_page'] ?? 200), 200))
            ->get()
            ->map(fn (SupplierReturnDocument $doc) => $this->formatDocument($doc, $user));
    }

    public function findForUser(User $user, int $id): SupplierReturnDocument
    {
        $query = SupplierReturnDocument::query()
            ->where('organization_id', $user->organization_id);

        $this->access->scopeBranchIfLimited($query, $user);

        return $query->findOrFail($id);
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data, ?UploadedFile $proof = null): SupplierReturnDocument
    {
        $this->assertSupplierInOrg($user, (int) $data['supplier_id']);
        $this->assertBranchAccess($user, (int) $data['branch_id']);
        $lines = $this->normalizeLines($data, $user);

        return DB::transaction(function () use ($user, $data, $lines, $proof) {
            $lpoNo = ! empty($data['lpo_no']) ? (int) $data['lpo_no'] : null;
            $doc = SupplierReturnDocument::create([
                'organization_id' => $user->organization_id,
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => (int) $data['branch_id'],
                'source_type' => $lpoNo ? 'lpo' : 'manual',
                'lpo_no' => $lpoNo,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'reason_scope' => ($data['reason_scope'] ?? 'order') === 'per_product' ? 'per_product' : 'order',
                'return_reason' => $data['return_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending_approval',
                'returned_by' => $user->id,
            ]);

            $this->syncLines($doc, $lines);

            if ($proof !== null) {
                $this->proofService->store(
                    $doc,
                    $proof,
                    \App\Support\OrganizationPublicStorage::path($doc->organization_id ?? $user->organization_id, 'returns', 'supplier', (string) $doc->id),
                );
                $doc->refresh();
            }

            $doc->load(['supplier', 'returnedByUser']);
            $this->createApprovalRequest($user, $doc);

            return $doc->fresh(['lines', 'supplier', 'returnedByUser']);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(SupplierReturnDocument $doc, User $user, array $data, ?UploadedFile $proof = null): SupplierReturnDocument
    {
        if ($doc->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'Only pending returns can be edited.',
            ]);
        }

        $this->assertCanMutate($doc, $user);

        return DB::transaction(function () use ($doc, $user, $data, $proof) {
            if (isset($data['supplier_id'])) {
                $this->assertSupplierInOrg($user, (int) $data['supplier_id']);
            }
            if (isset($data['branch_id'])) {
                $this->assertBranchAccess($user, (int) $data['branch_id']);
            }

            $lines = isset($data['lines']) ? $this->normalizeLines($data, $user, $doc) : null;

            $lpoNo = array_key_exists('lpo_no', $data)
                ? ($data['lpo_no'] ? (int) $data['lpo_no'] : null)
                : $doc->lpo_no;

            $doc->update(array_filter([
                'supplier_id' => $data['supplier_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'source_type' => array_key_exists('lpo_no', $data)
                    ? ($lpoNo ? 'lpo' : 'manual')
                    : null,
                'lpo_no' => array_key_exists('lpo_no', $data) ? $lpoNo : null,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'reason_scope' => isset($data['reason_scope'])
                    ? (($data['reason_scope'] === 'per_product') ? 'per_product' : 'order')
                    : null,
                'return_reason' => $data['return_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], fn ($v) => $v !== null));

            if ($lines !== null) {
                $doc->lines()->delete();
                $this->syncLines($doc, $lines);
            }

            if ($proof !== null) {
                $this->proofService->store(
                    $doc,
                    $proof,
                    \App\Support\OrganizationPublicStorage::path($doc->organization_id ?? $user->organization_id, 'returns', 'supplier', (string) $doc->id),
                );
            }

            return $doc->fresh(['lines', 'supplier', 'returnedByUser']);
        });
    }

    public function approve(SupplierReturnDocument $doc, User $user): SupplierReturnDocument
    {
        $this->assertCanApprove($user);

        if ($doc->status === 'approved') {
            return $doc;
        }

        if ($doc->status === 'rejected') {
            throw ValidationException::withMessages([
                'status' => 'Rejected returns cannot be approved.',
            ]);
        }

        return DB::transaction(function () use ($doc, $user) {
            $doc->load('lines');
            $journalTotal = 0.0;

            foreach ($doc->lines as $line) {
                $deductQty = $this->resolveStockDeductQty($doc, $line);
                if ($deductQty <= 0) {
                    continue;
                }

                $ledgerData = $this->withProductUnitCost([
                    'branch_id' => $doc->branch_id,
                    'product_code' => $line->product_code,
                    'stock_location' => $line->stock_location,
                    'transaction_type' => 'SUPPLIER_RETURN',
                    'reference_type' => 'supplier_return_document',
                    'reference_id' => $doc->id,
                    'quantity_change' => -abs($deductQty),
                    'notes' => $line->reason ?: $doc->return_reason,
                    'created_by' => $user->id,
                ], (int) $user->organization_id);

                $this->postStockLedger($ledgerData, allowBelowStock: false);

                $unitCost = isset($ledgerData['unit_cost']) ? (float) $ledgerData['unit_cost'] : null;
                $factor = \App\Services\Inventory\StockCostCalculation::conversionFactorForOrganizationProduct(
                    (int) $user->organization_id,
                    (string) $line->product_code,
                );
                $lineAmount = app(InventoryMovementJournalService::class)->amountFromQtyCost($deductQty, $unitCost, $factor);
                if ($lineAmount !== null) {
                    $journalTotal += $lineAmount;
                }

                $line->update(['stock_deduct_qty' => $deductQty]);

                SupplierReturn::create([
                    'supplier_id' => $doc->supplier_id,
                    'branch_id' => $doc->branch_id,
                    'product_code' => $line->product_code,
                    'quantity' => $deductQty,
                    'package_type' => $line->package_type,
                    'uom_label' => $line->uom_label,
                    'stock_location' => $line->stock_location,
                    'reason' => $line->reason ?: $doc->return_reason,
                    'reference_type' => 'supplier_return_document',
                    'reference_id' => $doc->id,
                    'returned_by' => $doc->returned_by,
                ]);
            }

            $doc->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            $gate = app(ErpContext::class)->gateForUser($user);
            $this->postInventoryMovementJournalAmount(
                $user,
                $gate,
                InventoryMovementJournalService::MOVEMENT_SUPPLIER_RETURN,
                $journalTotal,
                'SR-'.$doc->id,
                'Supplier return #'.$doc->id,
                (int) $doc->branch_id,
                'supplier_return_document',
                (int) $doc->id,
            );

            return $doc->fresh(['lines', 'supplier', 'returnedByUser', 'approvedByUser']);
        });
    }

    public function reject(SupplierReturnDocument $doc, User $user, ?string $reason = null): SupplierReturnDocument
    {
        $this->assertCanApprove($user);

        if ($doc->status === 'rejected') {
            return $doc;
        }

        if ($doc->status === 'approved') {
            return DB::transaction(function () use ($doc, $user, $reason) {
                $this->reverseApprovedStock($doc, $user);
                $this->deleteLegacySupplierReturns($doc);

                $doc->update([
                    'status' => 'rejected',
                    'rejected_by' => $user->id,
                    'rejected_at' => now(),
                    'rejection_reason' => $reason,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);

                return $doc->fresh(['lines', 'supplier', 'returnedByUser', 'rejectedByUser']);
            });
        }

        $doc->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $doc->fresh(['lines', 'supplier', 'returnedByUser', 'rejectedByUser']);
    }

    public function deleteDocument(SupplierReturnDocument $doc, User $user): void
    {
        if ($doc->status === 'approved') {
            DB::transaction(function () use ($doc, $user) {
                $this->assertCanApprove($user);
                $this->reverseApprovedStock($doc, $user);
                $this->deleteLegacySupplierReturns($doc);
                $this->proofService->deleteExisting($doc);
                $doc->lines()->delete();
                $doc->delete();
            });

            return;
        }

        if ($doc->status === 'pending_approval') {
            $this->assertCanMutate($doc, $user);
        } else {
            $this->assertCanApprove($user);
        }

        $this->proofService->deleteExisting($doc);
        $doc->lines()->delete();
        $doc->delete();
    }

    public function formatDocument(SupplierReturnDocument $doc, User $user): array
    {
        $doc->loadMissing(['lines', 'supplier', 'returnedByUser']);

        $lpoModel = null;
        $lpoOrderDate = null;
        $poNumber = null;
        if ($doc->lpo_no) {
            $lpoModel = LpoMst::query()->where('lpo_no', $doc->lpo_no)->first();
            if ($lpoModel) {
                $lpoOrderDate = $lpoModel->created_at ?? $lpoModel->sent_at;
                $poNumber = app(\App\Services\LpoModuleService::class)
                    ->formatPoNumber((int) $lpoModel->lpo_seq, $lpoOrderDate);
            }
        }

        return [
            'id' => (int) $doc->id,
            'organization_id' => (int) $doc->organization_id,
            'supplier_id' => (int) $doc->supplier_id,
            'supplier_name' => $doc->supplier?->supplier_name,
            'branch_id' => (int) $doc->branch_id,
            'source_type' => $doc->lpo_no ? 'lpo' : ($doc->source_type === 'lpo' ? 'lpo' : 'manual'),
            'lpo_no' => $doc->lpo_no ? (int) $doc->lpo_no : null,
            'lpo_seq' => $lpoModel ? (int) $lpoModel->lpo_seq : null,
            'po_number' => $poNumber,
            'lpo_order_date' => $lpoOrderDate,
            'supplier_invoice_no' => $doc->supplier_invoice_no,
            'reason_scope' => $doc->reason_scope,
            'return_reason' => $doc->return_reason,
            'proof' => $this->proofService->meta($doc, '/supplier-return-documents/'.$doc->id.'/proof/file'),
            'notes' => $doc->notes,
            'status' => $doc->status,
            'status_label' => $this->statusLabel($doc->status),
            'returned_by' => (int) $doc->returned_by,
            'returned_by_name' => $doc->returnedByUser?->full_name ?? $doc->returnedByUser?->username,
            'created_at' => $doc->created_at?->toDateTimeString(),
            'approved_at' => $doc->approved_at?->toDateTimeString(),
            'rejected_at' => $doc->rejected_at?->toDateTimeString(),
            'rejection_reason' => $doc->rejection_reason,
            'reference' => $poNumber ? 'LPO '.$poNumber : 'Manual',
            'can_edit' => $doc->status === 'pending_approval' && $this->canMutate($doc, $user),
            'can_delete' => ($doc->status === 'pending_approval' && $this->canMutate($doc, $user))
                || ($doc->status === 'approved' && $this->canApprove($user)),
            'can_approve' => $doc->status === 'pending_approval' && $this->canApprove($user),
            'can_reject' => in_array($doc->status, ['pending_approval', 'approved'], true) && $this->canApprove($user),
            'action_request' => $doc->status === 'pending_approval' && $user
                ? app(ActionRequestService::class)->presentPendingFor(
                    $user,
                    'supplier_return',
                    'supplier_return_document',
                    (int) $doc->id,
                )
                : null,
            'lines' => $doc->lines->map(fn (SupplierReturnDocumentLine $line) => [
                'id' => (int) $line->id,
                'product_code' => $line->product_code,
                'product_name' => $line->product_name,
                'quantity' => (float) $line->quantity,
                'package_type' => $line->package_type,
                'package_type_label' => $line->package_type_label ?? $this->packageTypeLabel($line->package_type),
                'uom_label' => $line->uom_label,
                'stock_location' => $line->stock_location,
                'reason' => $line->reason,
                'stock_deduct_qty' => $line->stock_deduct_qty !== null ? (float) $line->stock_deduct_qty : null,
            ])->values()->all(),
        ];
    }

    /** @return array<string, float> product_code => qty (approved + pending) */
    public function returnedQtyByProductForLpo(int $lpoNo, ?int $excludeDocumentId = null): array
    {
        $lpo = LpoMst::query()->where('lpo_no', $lpoNo)->first();
        if (! $lpo) {
            return [];
        }

        $query = SupplierReturnDocumentLine::query()
            ->whereHas('document', function ($q) use ($lpo, $excludeDocumentId) {
                $q->where('lpo_no', $lpo->lpo_no)
                    ->whereIn('status', ['pending_approval', 'approved']);
                if ($excludeDocumentId) {
                    $q->where('id', '!=', $excludeDocumentId);
                }
            });

        return $query
            ->select('product_code', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_code')
            ->pluck('qty', 'product_code')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    /** @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    protected function normalizeLines(array $data, User $user, ?SupplierReturnDocument $existing = null): array
    {
        $rawLines = $data['lines'] ?? [];
        if ($rawLines === []) {
            throw ValidationException::withMessages([
                'lines' => ['Add at least one product line.'],
            ]);
        }

        $sourceType = ($data['source_type'] ?? $existing?->source_type ?? 'manual') === 'lpo' ? 'lpo' : 'manual';
        $lpoNo = ! empty($data['lpo_no']) ? (int) $data['lpo_no'] : ($existing?->lpo_no);
        $reasonScope = ($data['reason_scope'] ?? $existing?->reason_scope ?? 'order') === 'per_product'
            ? 'per_product'
            : 'order';
        $docNotes = trim((string) ($data['return_reason'] ?? $data['notes'] ?? $existing?->return_reason ?? ''));

        if (strlen($docNotes) < 3) {
            throw ValidationException::withMessages([
                'return_reason' => ['Return reason is required (at least 3 characters).'],
            ]);
        }

        $returnedByProduct = $lpoNo
            ? $this->returnedQtyByProductForLpo($lpoNo, $existing?->id)
            : [];

        $lpoLines = $lpoNo
            ? LpoTxn::query()->where('lpo_no', $lpoNo)->get()->keyBy('product_code')
            : collect();

        $normalized = [];
        foreach ($rawLines as $i => $row) {
            $productCode = (string) ($row['product_code'] ?? '');
            $qty = (float) ($row['quantity'] ?? 0);
            if ($productCode === '' || $qty <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$i}.quantity" => ['Enter a valid quantity for each line.'],
                ]);
            }

            $product = Product::query()->where('product_code', $productCode)->first();
            $packageType = in_array($row['package_type'] ?? '', ['full_package', 'pieces', 'partial'], true)
                ? $row['package_type']
                : 'partial';

            if ($sourceType === 'lpo' && $lpoNo) {
                $txn = $lpoLines->get($productCode);
                if (! $txn) {
                    throw ValidationException::withMessages([
                        "lines.{$i}.product_code" => ["Product {$productCode} is not on LPO {$lpoNo}."],
                    ]);
                }
                $received = (float) ($txn->received_qty ?? 0);
                $offer = (float) ($txn->offer_qty ?? 0);
                $paidReceived = max(0.0, $received - $offer);
                $already = (float) ($returnedByProduct[$productCode] ?? 0);
                $maxReturn = max(0, min($paidReceived, (float) ($txn->ordered_qty ?? 0)) - $already);
                if ($qty > $maxReturn + 0.0001) {
                    throw ValidationException::withMessages([
                        "lines.{$i}.quantity" => ["Return quantity exceeds available ({$maxReturn}) for {$productCode}."],
                    ]);
                }
            }

            $lineReason = $reasonScope === 'per_product'
                ? trim((string) ($row['reason'] ?? ''))
                : $docNotes;

            if ($reasonScope === 'per_product' && strlen($lineReason) < 3) {
                throw ValidationException::withMessages([
                    "lines.{$i}.reason" => ['Each line needs a return reason.'],
                ]);
            }

            $normalized[] = [
                'product_code' => $productCode,
                'product_name' => $product?->product_name ?? $productCode,
                'quantity' => $qty,
                'package_type' => $packageType,
                'package_type_label' => $this->packageTypeLabel($packageType, $row['uom_label'] ?? null),
                'uom_label' => $row['uom_label'] ?? null,
                'stock_location' => in_array($row['stock_location'] ?? '', ['shop', 'store'], true)
                    ? $row['stock_location']
                    : 'store',
                'reason' => $lineReason,
                'lpo_txn_id' => $lpoLines->get($productCode)?->id,
            ];
        }

        return $normalized;
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function syncLines(SupplierReturnDocument $doc, array $lines): void
    {
        foreach ($lines as $row) {
            SupplierReturnDocumentLine::create([
                'document_id' => $doc->id,
                ...$row,
            ]);
        }
    }

    protected function resolveStockDeductQty(SupplierReturnDocument $doc, SupplierReturnDocumentLine $line): float
    {
        $qty = (float) $line->quantity;
        if ($qty <= 0) {
            return 0;
        }

        if ($doc->source_type === 'lpo' && $doc->lpo_no) {
            $txn = LpoTxn::query()
                ->where('lpo_no', $doc->lpo_no)
                ->where('product_code', $line->product_code)
                ->first();
            if ($txn) {
                $received = (float) ($txn->received_qty ?? 0);
                $approvedOther = (float) SupplierReturnDocumentLine::query()
                    ->where('product_code', $line->product_code)
                    ->where('id', '!=', $line->id)
                    ->whereHas('document', fn ($q) => $q
                        ->where('lpo_no', $doc->lpo_no)
                        ->where('status', 'approved')
                        ->where('id', '!=', $doc->id))
                    ->sum('stock_deduct_qty');

                return max(0, min($qty, $received - $approvedOther));
            }
        }

        return $qty;
    }

    protected function reverseApprovedStock(SupplierReturnDocument $doc, User $user): void
    {
        $doc->load('lines');
        $journalTotal = 0.0;

        foreach ($doc->lines as $line) {
            $deductQty = (float) ($line->stock_deduct_qty ?? 0);
            if ($deductQty <= 0) {
                continue;
            }

            $unitCost = InventoryTransaction::query()
                ->where('reference_type', 'supplier_return_document')
                ->where('reference_id', $doc->id)
                ->where('product_code', $line->product_code)
                ->where('quantity_change', '<', 0)
                ->orderByDesc('id')
                ->value('unit_cost');
            $unitCost = ($unitCost !== null && (float) $unitCost > 0)
                ? (float) $unitCost
                : ($this->productUnitCost((int) $user->organization_id, (string) $line->product_code) ?? 0);

            $this->postStockLedger([
                'branch_id' => $doc->branch_id,
                'product_code' => $line->product_code,
                'stock_location' => $line->stock_location,
                'transaction_type' => 'SUPPLIER_RETURN',
                'reference_type' => 'supplier_return_document_reversal',
                'reference_id' => $doc->id,
                'quantity_change' => abs($deductQty),
                'unit_cost' => $unitCost > 0 ? $unitCost : null,
                'notes' => 'Reversal of supplier return #'.$doc->id,
                'created_by' => $user->id,
            ], allowBelowStock: true);

            $factor = \App\Services\Inventory\StockCostCalculation::conversionFactorForOrganizationProduct(
                (int) $user->organization_id,
                (string) $line->product_code,
            );
            $lineAmount = app(InventoryMovementJournalService::class)->amountFromQtyCost($deductQty, $unitCost > 0 ? $unitCost : null, $factor);
            if ($lineAmount !== null) {
                $journalTotal += $lineAmount;
            }
        }

        if ($journalTotal > 0) {
            $gate = app(ErpContext::class)->gateForUser($user);
            $this->postInventoryMovementJournalAmount(
                $user,
                $gate,
                InventoryMovementJournalService::MOVEMENT_SUPPLIER_RETURN_REVERSAL,
                $journalTotal,
                'SR-REV-'.$doc->id.'-'.now()->timestamp,
                'Supplier return reversal #'.$doc->id,
                (int) $doc->branch_id,
                'supplier_return_document_reversal',
                (int) $doc->id,
            );
        }
    }

    protected function deleteLegacySupplierReturns(SupplierReturnDocument $doc): void
    {
        SupplierReturn::query()
            ->where('reference_type', 'supplier_return_document')
            ->where('reference_id', $doc->id)
            ->delete();
    }

    protected function assertSupplierInOrg(User $user, int $supplierId): void
    {
        $exists = Supplier::query()
            ->where('id', $supplierId)
            ->where('organization_id', $user->organization_id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Supplier not found in your organization.'],
            ]);
        }
    }

    protected function assertBranchAccess(User $user, int $branchId): void
    {
        $limitedBranch = $this->access->branchId($user);
        if ($limitedBranch !== null && $limitedBranch !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['You can only record returns for your assigned branch.'],
            ]);
        }
    }

    protected function assertCanApprove(User $user): void
    {
        if (! $this->canApprove($user)) {
            throw ValidationException::withMessages([
                'authorization' => ['Only administrators or purchasing managers can approve supplier returns.'],
            ]);
        }
    }

    protected function assertCanMutate(SupplierReturnDocument $doc, User $user): void
    {
        if (! $this->canMutate($doc, $user)) {
            throw ValidationException::withMessages([
                'authorization' => ['You cannot modify this return.'],
            ]);
        }
    }

    protected function canApprove(User $user): bool
    {
        return $this->permissions->canApproveSupplierReturns($user);
    }

    protected function createApprovalRequest(User $user, SupplierReturnDocument $doc): void
    {
        $requesterName = $user->full_name ?: $user->username;
        $supplierName = $doc->supplier?->supplier_name ?? 'Supplier';
        $returnLabel = 'SR-'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT);
        $actionUrl = \App\Services\Notifications\NotificationActionUrlBuilder::for('supplier_return', (int) $doc->id);
        $returnReason = trim((string) ($doc->return_reason ?? ''));
        $proof = $this->proofService->meta($doc, '/supplier-return-documents/'.$doc->id.'/proof/file');

        $message = "{$requesterName} requested supplier return {$returnLabel} for {$supplierName}.";
        if ($returnReason !== '') {
            $message .= " Reason: {$returnReason}.";
        }
        if ($proof !== null) {
            $message .= ' Proof attached.';
        }

        app(\App\Services\Notifications\ActionRequestService::class)->requestApproval($user, [
            'type' => 'supplier_return',
            'module' => 'purchasing',
            'reference_type' => 'supplier_return_document',
            'reference_id' => (int) $doc->id,
            'approver_permission' => 'purchasing.manage',
            'title' => 'Supplier return approval required',
            'message' => $message,
            'reason' => $returnReason !== '' ? $returnReason : null,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => array_filter([
                'action_url' => $actionUrl,
                'return_label' => $returnLabel,
                'supplier_name' => $supplierName,
                'return_reason' => $returnReason !== '' ? $returnReason : null,
                'proof' => $proof,
            ], fn ($value) => $value !== null),
        ]);
    }

    protected function canMutate(SupplierReturnDocument $doc, User $user): bool
    {
        if ($this->canApprove($user)) {
            return true;
        }

        return (int) $doc->returned_by === (int) $user->id
            && $this->permissions->hasPermission($user, 'purchasing.supplier_returns.create');
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Pending approval',
        };
    }

    protected function packageTypeLabel(string $packageType, ?string $uom = null): string
    {
        return match ($packageType) {
            'full_package' => $uom ? "Full package ({$uom})" : 'Full package',
            'pieces' => 'Pieces / loose',
            default => 'Partial',
        };
    }
}
