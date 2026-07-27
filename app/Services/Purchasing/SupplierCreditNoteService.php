<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteLine;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierCreditNoteService
{
    public function __construct(
        protected UserAccessService $access,
        protected UserPermissionService $permissions,
    ) {}

    /** @param  array<string, mixed>  $filters */
    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = SupplierCreditNote::query()
            ->with(['supplier', 'createdByUser', 'lines'])
            ->where('organization_id', $user->organization_id);

        $this->access->scopeBranchIfLimited($query, $user);

        if (! empty($filters['branch_id']) && $this->access->branchId($user) === null) {
            $branchId = (int) $filters['branch_id'];
            $this->access->assertBranchInOrganization($user, $branchId);
            $query->where('branch_id', $branchId);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('credit_date', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('credit_date', '<=', $filters['to_date']);
        }

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('credit_note_no', 'like', "%{$q}%")
                    ->orWhere('reason', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('supplier_invoice_no', 'like', "%{$q}%")
                    ->orWhereHas(
                        'supplier',
                        fn ($supplier) => $supplier->where('supplier_name', 'like', "%{$q}%"),
                    );
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findForUser(User $user, int $id): SupplierCreditNote
    {
        $query = SupplierCreditNote::query()
            ->where('organization_id', $user->organization_id);

        $this->access->scopeBranchIfLimited($query, $user);

        return $query->findOrFail($id);
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): SupplierCreditNote
    {
        $this->assertSupplierInOrg($user, (int) $data['supplier_id']);
        $this->access->assertBranchAccess($user, (int) $data['branch_id']);

        $lines = $this->normalizeLines($data['lines'] ?? [], $user);
        $total = $this->resolveTotalAmount($data, $lines);

        return DB::transaction(function () use ($user, $data, $lines, $total) {
            $number = $this->allocateDocumentNumber((int) $user->organization_id);

            $note = SupplierCreditNote::create([
                ...$number,
                'organization_id' => $user->organization_id,
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => (int) $data['branch_id'],
                'credit_date' => $data['credit_date'] ?? now()->toDateString(),
                'total_amount' => $total,
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'lpo_no' => ! empty($data['lpo_no']) ? (int) $data['lpo_no'] : null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending_approval',
                'created_by' => $user->id,
            ]);

            $this->syncLines($note, $lines);

            return $note->fresh(['lines', 'supplier', 'createdByUser']);
        });
    }

    public function approve(SupplierCreditNote $note, User $user): SupplierCreditNote
    {
        $this->assertCanApprove($user);

        if ($note->status === 'approved') {
            return $note;
        }

        if ($note->status === 'rejected') {
            throw ValidationException::withMessages([
                'status' => 'Rejected credit notes cannot be approved.',
            ]);
        }

        $note->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        return $note->fresh(['lines', 'supplier', 'createdByUser', 'approvedByUser']);
    }

    public function reject(SupplierCreditNote $note, User $user, ?string $reason = null): SupplierCreditNote
    {
        $this->assertCanApprove($user);

        if ($note->status === 'rejected') {
            return $note;
        }

        $note->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $note->fresh(['lines', 'supplier', 'createdByUser', 'rejectedByUser']);
    }

    public function deleteNote(SupplierCreditNote $note, User $user): void
    {
        if ($note->status === 'approved') {
            throw ValidationException::withMessages([
                'status' => 'Approved supplier credit notes cannot be deleted. Reject instead.',
            ]);
        }

        if ($note->status === 'pending_approval') {
            $this->assertCanCreate($user);
        } else {
            $this->assertCanApprove($user);
        }

        $note->lines()->delete();
        $note->delete();
    }

    public function withActionFlags(SupplierCreditNote $note, User $user): SupplierCreditNote
    {
        $canApprove = $this->canApprove($user);
        $canCreate = $this->canCreate($user);
        $pending = $note->status === 'pending_approval';
        $approved = $note->status === 'approved';

        $note->setAttribute('can_approve', $pending && $canApprove);
        $note->setAttribute('can_reject', in_array($note->status, ['pending_approval', 'approved'], true) && $canApprove);
        $note->setAttribute('can_delete', ($pending && $canCreate) || ($approved && $canApprove));
        $note->setAttribute('can_print', true);
        $note->setAttribute('status_label', $this->statusLabel($note->status));

        return $note;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function normalizeLines(array $lines, User $user): array
    {
        $normalized = [];
        $lineNo = 1;

        foreach ($lines as $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $description = trim((string) ($line['description'] ?? ''));
            $productCode = trim((string) ($line['product_code'] ?? ''));
            if ($productCode === '' && $description === '') {
                continue;
            }

            $productName = trim((string) ($line['product_name'] ?? ''));
            if ($productCode !== '') {
                $product = Product::query()
                    ->where('organization_id', $user->organization_id)
                    ->where('product_code', $productCode)
                    ->first();
                if (! $product) {
                    throw ValidationException::withMessages([
                        'lines' => "Product {$productCode} was not found.",
                    ]);
                }
                $productName = $productName ?: (string) $product->product_name;
            }

            $normalized[] = [
                'product_code' => $productCode !== '' ? $productCode : null,
                'product_name' => $productName !== '' ? $productName : null,
                'description' => $description !== '' ? $description : null,
                'amount' => $amount,
                'line_no' => $line['line_no'] ?? $lineNo,
            ];
            $lineNo++;
        }

        return $normalized;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function resolveTotalAmount(array $data, array $lines): float
    {
        if ($lines !== []) {
            return round(array_sum(array_column($lines, 'amount')), 2);
        }

        $total = round((float) ($data['total_amount'] ?? 0), 2);
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => 'Enter a credit amount or add at least one line.',
            ]);
        }

        return $total;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function syncLines(SupplierCreditNote $note, array $lines): void
    {
        foreach ($lines as $line) {
            SupplierCreditNoteLine::create([
                'supplier_credit_note_id' => $note->id,
                ...$line,
            ]);
        }
    }

    /** @return array{credit_note_seq: int, credit_note_no: string} */
    protected function allocateDocumentNumber(int $organizationId): array
    {
        $lastSeq = SupplierCreditNote::query()
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->max('credit_note_seq');

        $seq = ((int) $lastSeq) + 1;

        return [
            'credit_note_seq' => $seq,
            'credit_note_no' => 'SCN-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
        ];
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Pending approval',
        };
    }

    protected function assertSupplierInOrg(User $user, int $supplierId): void
    {
        $exists = Supplier::query()
            ->where('organization_id', $user->organization_id)
            ->where('id', $supplierId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier not found.',
            ]);
        }
    }

    protected function canApprove(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'purchasing.manage');
    }

    protected function canCreate(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'purchasing.manage')
            || $this->permissions->hasPermission($user, 'purchasing.supplier_returns.create');
    }

    protected function assertCanApprove(User $user): void
    {
        if (! $this->canApprove($user)) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to approve supplier credit notes.',
            ]);
        }
    }

    protected function assertCanCreate(User $user): void
    {
        if (! $this->canCreate($user)) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to create supplier credit notes.',
            ]);
        }
    }
}
