<?php

namespace App\Services;

use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\LpoStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierReturnService
{
    public function __construct(
        protected LpoInventoryService $inventory,
        protected LpoModuleService $lpoModule,
        protected SupplierBalanceService $supplierBalances,
    ) {}

    public function paginatedList(Request $request): array
    {
        $query = SupplierReturn::query()
            ->from('supplier_returns as sr')
            ->join('suppliers as s', 's.id', '=', 'sr.supplier_id')
            ->join('products as p', 'p.product_code', '=', 'sr.product_code')
            ->leftJoin('users as u', 'u.id', '=', 'sr.returned_by')
            ->select([
                'sr.*',
                's.supplier_name',
                'p.product_name',
                'u.full_name as returned_by_name',
                'u.username as returned_by_username',
            ])
            ->orderByDesc('sr.id');

        if ($request->filled('supplier_id')) {
            $query->where('sr.supplier_id', (int) $request->input('supplier_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('sr.branch_id', (int) $request->input('branch_id'));
        }
        if ($request->filled('reference_type')) {
            $query->where('sr.reference_type', (string) $request->input('reference_type'));
        }
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('p.product_name', 'like', "%{$q}%")
                    ->orWhere('sr.product_code', 'like', "%{$q}%")
                    ->orWhere('s.supplier_name', 'like', "%{$q}%")
                    ->orWhere('sr.reason', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())->map(fn ($row) => $this->mapReturn($row))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    public function listForLpo(int $lpoNo): array
    {
        return SupplierReturn::query()
            ->where('reference_type', 'lpo')
            ->where('reference_id', $lpoNo)
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupplierReturn $r) => $this->mapReturn($r))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromLpo(int $lpoNo, array $data, User $user): SupplierReturn
    {
        $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->firstOrFail();

        if ((int) $lpo->lpo_status_code < LpoStatus::AWAITING_RECEIVE) {
            throw new InvalidArgumentException(
                'Supplier returns are only allowed after the LPO has been sent and stock received.',
            );
        }

        $productCode = (string) $data['product_code'];
        $qty = (float) $data['quantity'];
        if ($qty <= 0) {
            throw new InvalidArgumentException('Return quantity must be greater than zero.');
        }

        $txn = LpoTxn::query()
            ->where('lpo_no', $lpoNo)
            ->where('product_code', $productCode)
            ->first();

        if (! $txn) {
            throw new InvalidArgumentException('Product is not on this LPO.');
        }

        $maxReturn = $this->lpoModule->maxReturnQty($txn);
        if ($qty > $maxReturn + 0.0001) {
            throw new InvalidArgumentException(
                'Return quantity cannot exceed remaining on this LPO line (max '
                . round($maxReturn, 3) . ').',
            );
        }

        $stockDeductQty = $this->lpoModule->stockDeductQtyForReturn($txn, $qty);

        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 3) {
            throw new InvalidArgumentException('Enter a reason for the return (e.g. damaged goods).');
        }

        return DB::transaction(function () use ($lpo, $txn, $data, $qty, $reason, $user, $stockDeductQty) {
            $return = SupplierReturn::create([
                'supplier_id' => (int) $lpo->supplier_id,
                'branch_id' => (int) $data['branch_id'],
                'product_code' => $txn->product_code,
                'quantity' => $qty,
                'package_type' => $data['package_type'] ?? 'partial',
                'uom_label' => $data['uom_label'] ?? $txn->uom,
                'stock_location' => $data['stock_location'] ?? 'store',
                'reason' => $reason,
                'reference_type' => 'lpo',
                'reference_id' => (int) $lpo->lpo_no,
                'returned_by' => (int) $user->id,
            ]);

            if ($stockDeductQty > 0) {
                $this->inventory->adjustStock([
                    'branch_id' => (int) $data['branch_id'],
                    'product_code' => $txn->product_code,
                    'stock_location' => $data['stock_location'] ?? 'store',
                    'transaction_type' => 'SUPPLIER_RETURN',
                    'reference_type' => 'supplier_return',
                    'reference_id' => $return->id,
                    'quantity_change' => -abs($stockDeductQty),
                    'unit_cost' => (float) ($txn->cost_price ?? 0),
                    'notes' => $reason,
                    'created_by' => (int) $user->id,
                ]);
            }

            $this->lpoModule->syncClearedStatus((int) $lpo->lpo_no);
            $this->lpoModule->syncReturnStatus((int) $lpo->lpo_no);
            $this->supplierBalances->recalculate((int) $lpo->supplier_id);

            return $return->fresh();
        });
    }

    /**
     * Return stock to a supplier without an LPO (e.g. legacy / opening stock).
     *
     * @param  array<string, mixed>  $data
     */
    public function createManual(array $data, User $user): SupplierReturn
    {
        $supplierId = (int) $data['supplier_id'];
        Supplier::query()->whereNull('deleted_at')->where('id', $supplierId)->firstOrFail();

        $productCode = (string) $data['product_code'];
        $product = Product::query()->where('product_code', $productCode)->first();
        if (! $product) {
            throw new InvalidArgumentException('Product not found.');
        }

        $qty = (float) $data['quantity'];
        if ($qty <= 0) {
            throw new InvalidArgumentException('Return quantity must be greater than zero.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if (strlen($reason) < 3) {
            throw new InvalidArgumentException('Enter a reason for the return (e.g. expired legacy stock).');
        }

        $unitCost = isset($data['unit_cost'])
            ? (float) $data['unit_cost']
            : (float) ($product->last_cost_price ?? 0);

        return DB::transaction(function () use ($data, $supplierId, $productCode, $product, $qty, $reason, $unitCost, $user) {
            $return = SupplierReturn::create([
                'supplier_id' => $supplierId,
                'branch_id' => (int) $data['branch_id'],
                'product_code' => $productCode,
                'quantity' => $qty,
                'package_type' => $data['package_type'] ?? 'partial',
                'uom_label' => $data['uom_label'] ?? null,
                'stock_location' => $data['stock_location'] ?? 'store',
                'reason' => $reason,
                'reference_type' => 'manual',
                'reference_id' => null,
                'returned_by' => (int) $user->id,
            ]);

            $this->inventory->adjustStock([
                'branch_id' => (int) $data['branch_id'],
                'product_code' => $productCode,
                'stock_location' => $data['stock_location'] ?? 'store',
                'transaction_type' => 'SUPPLIER_RETURN',
                'reference_type' => 'supplier_return',
                'reference_id' => $return->id,
                'quantity_change' => -abs($qty),
                'unit_cost' => $unitCost,
                'notes' => $reason,
                'created_by' => (int) $user->id,
            ]);

            $this->supplierBalances->recalculate($supplierId);

            return $return->fresh();
        });
    }

    public function mapReturn(object $r): array
    {
        $refType = $r->reference_type ?? null;
        $returnedBy = $r->returned_by_name ?? $r->returned_by_username ?? null;

        return [
            'id' => (int) $r->id,
            'supplier_id' => (int) $r->supplier_id,
            'supplier_name' => $r->supplier_name ?? null,
            'branch_id' => (int) $r->branch_id,
            'product_code' => $r->product_code,
            'product_name' => $r->product_name ?? null,
            'quantity' => (float) $r->quantity,
            'package_type' => $r->package_type,
            'uom_label' => $r->uom_label,
            'stock_location' => $r->stock_location,
            'reason' => $r->reason,
            'reference_type' => $refType,
            'lpo_no' => $refType === 'lpo' ? (int) $r->reference_id : null,
            'is_manual' => $refType === 'manual',
            'returned_by_name' => $returnedBy,
            'created_at' => $r->created_at
                ? \Carbon\Carbon::parse($r->created_at)->format('Y-m-d H:i')
                : null,
        ];
    }
}
