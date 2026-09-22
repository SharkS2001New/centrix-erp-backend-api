<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\CurrentStock;
use App\Models\Damage;
use App\Models\LpoSupplierInvoice;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use App\Services\Accounting\InventoryMovementJournalService;
use App\Services\Audit\OperationalAuditService;
use App\Services\Erp\ErpContext;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Expiring / expired GRN lots (stock_receipts with expiry_date).
 * Stock remains aggregate; Clear write-offs against on-hand and dismisses the lot from this list.
 */
class ExpiringBatchService
{
    use HandlesInventory;

    public function batchTrackingEnabled(?int $organizationId): bool
    {
        if (! $organizationId) {
            return false;
        }

        $org = \App\Models\Organization::query()->find($organizationId);
        $inventory = is_array($org?->module_settings['inventory'] ?? null)
            ? $org->module_settings['inventory']
            : [];

        return filter_var($inventory['enable_receive_batch_tracking'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && Schema::hasColumn('stock_receipts', 'expiry_date');
    }

    public function list(Request $request, User $user, int $organizationId): LengthAwarePaginator
    {
        if (! $this->batchTrackingEnabled($organizationId)) {
            throw ValidationException::withMessages([
                'batch_tracking' => 'Batch / expiry tracking is not enabled for this organization.',
            ]);
        }

        $today = Carbon::today()->toDateString();
        $withinDays = min(max((int) $request->input('within_days', 30), 1), 365);
        $horizon = Carbon::today()->addDays($withinDays)->toDateString();
        $status = strtolower(trim((string) $request->input('status', 'open')));
        // open = uncleared expired + soon-to-expire (default list)
        if (! in_array($status, ['open', 'expired', 'expiring', 'cleared', 'all'], true)) {
            $status = 'open';
        }

        $query = StockReceipt::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('expiry_date')
            ->orderBy('expiry_date')
            ->orderByDesc('id');

        if ($status === 'cleared') {
            $query->whereNotNull('expiry_cleared_at');
        } elseif ($status === 'expired') {
            $query->whereNull('expiry_cleared_at')->whereDate('expiry_date', '<', $today);
        } elseif ($status === 'expiring') {
            $query->whereNull('expiry_cleared_at')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $horizon);
        } elseif ($status === 'open') {
            $query->whereNull('expiry_cleared_at')
                ->whereDate('expiry_date', '<=', $horizon);
        }

        if ($branchId = $request->input('branch_id') ?? $request->input('filter.branch_id')) {
            $query->where('branch_id', (int) $branchId);
        }
        if ($productCode = trim((string) ($request->input('product_code') ?? $request->input('filter.product_code') ?? ''))) {
            $query->where('product_code', $productCode);
        }
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q, $organizationId) {
                $inner->where('batch_no', 'like', "%{$q}%")
                    ->orWhere('invoice_number', 'like', "%{$q}%")
                    ->orWhere('product_code', 'like', "%{$q}%")
                    ->orWhereHas(
                        'product',
                        fn ($p) => $p->where('organization_id', $organizationId)
                            ->where('product_name', 'like', "%{$q}%"),
                    );
            });
        }

        app(\App\Services\Auth\UserAccessService::class)->applyBranchListFilter($query, $user, $request);

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $paginator = $query->paginate($perPage);

        $rows = $paginator->getCollection();
        $productCodes = $rows->pluck('product_code')->unique()->filter()->values()->all();
        $branchIds = $rows->pluck('branch_id')->unique()->filter()->values()->all();
        $invoiceNos = $rows->pluck('invoice_number')->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();

        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->whereIn('product_code', $productCodes)
            ->get(['product_code', 'product_name', 'unit_id'])
            ->keyBy(fn ($p) => strtolower((string) $p->product_code));

        $stockByKey = [];
        if ($productCodes && $branchIds) {
            CurrentStock::query()
                ->whereIn('product_code', $productCodes)
                ->whereIn('branch_id', $branchIds)
                ->get()
                ->each(function ($row) use (&$stockByKey) {
                    $stockByKey[strtolower((string) $row->product_code).':'.(int) $row->branch_id] = $row;
                });
        }

        $supplierByInvoice = $this->suppliersByInvoiceNumber($organizationId, $invoiceNos);

        $paginator->setCollection(
            $rows->map(function (StockReceipt $receipt) use ($today, $products, $stockByKey, $supplierByInvoice) {
                return $this->mapRow($receipt, $today, $products, $stockByKey, $supplierByInvoice);
            }),
        );

        return $paginator;
    }

    /**
     * Clear an expired / expiring lot: write off remaining on-hand (if any) and dismiss from the list.
     *
     * @param  array{quantity?: float|int|string|null, reason?: string|null}  $input
     * @return array{receipt: array<string, mixed>, damage: ?Damage, pending_approval?: bool, action_request_id?: int, message?: string}
     */
    public function clear(StockReceipt $receipt, User $user, array $input = []): array
    {
        if (! $this->batchTrackingEnabled((int) $receipt->organization_id)) {
            throw ValidationException::withMessages([
                'batch_tracking' => 'Batch / expiry tracking is not enabled for this organization.',
            ]);
        }

        if ($receipt->expiry_date === null) {
            throw ValidationException::withMessages([
                'receipt' => 'This goods receipt has no expiry date.',
            ]);
        }

        if ($receipt->expiry_cleared_at) {
            throw ValidationException::withMessages([
                'receipt' => 'This batch has already been cleared from expiring stock.',
            ]);
        }

        $location = in_array((string) $receipt->stock_location, ['shop', 'store'], true)
            ? (string) $receipt->stock_location
            : 'store';
        $onHand = $this->stockOnHand((string) $receipt->product_code, (int) $receipt->branch_id, $location);
        $received = max(0, (float) $receipt->units_received);
        $requested = array_key_exists('quantity', $input) && $input['quantity'] !== null && $input['quantity'] !== ''
            ? (float) $input['quantity']
            : $received;
        if ($requested < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Clear quantity cannot be negative.',
            ]);
        }

        // Only deduct what is still on hand; allow clear-with-zero when stock already sold/moved.
        $deductQty = min($requested, max(0, $onHand));
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            $batch = trim((string) ($receipt->batch_no ?? ''));
            $expiry = $receipt->expiry_date?->format('Y-m-d') ?? '';
            $reason = 'Cleared expired/expiring batch'
                .($batch !== '' ? " {$batch}" : '')
                .($expiry !== '' ? " (expiry {$expiry})" : '')
                .' — physical stock finished or returned to supplier.';
        }

        $gate = app(ErpContext::class)->gateForUser($user);
        $approval = app(DamageApprovalService::class);

        return DB::transaction(function () use (
            $receipt,
            $user,
            $deductQty,
            $reason,
            $location,
            $gate,
            $approval,
            $onHand,
        ) {
            $damage = null;

            if ($deductQty > 0.0001) {
                $damageData = [
                    'product_code' => (string) $receipt->product_code,
                    'branch_id' => (int) $receipt->branch_id,
                    'organization_id' => (int) $receipt->organization_id,
                    'quantity' => $deductQty,
                    'stock_location' => $location,
                    'reason' => $reason,
                    'package_type' => 'partial',
                ];

                if ($approval->approvalEnabled($gate) && ! $approval->canDirectWriteOff($user)) {
                    $actionRequest = $approval->requestCreate($user, [
                        ...$damageData,
                        'stock_receipt_id' => (int) $receipt->id,
                        'batch_no' => $receipt->batch_no,
                        'expiry_date' => $receipt->expiry_date?->format('Y-m-d'),
                    ], $gate);

                    // Still dismiss from expiring list so it does not keep alerting while approval pending.
                    $receipt->update([
                        'expiry_cleared_at' => now(),
                        'expiry_cleared_by' => $user->id,
                        'expiry_cleared_qty' => $deductQty,
                        'expiry_clear_reason' => $reason.' (pending write-off approval)',
                    ]);

                    return [
                        'receipt' => $this->mapRowFresh($receipt->fresh(), Carbon::today()->toDateString()),
                        'damage' => null,
                        'pending_approval' => true,
                        'action_request_id' => (int) $actionRequest->id,
                        'message' => 'Batch cleared from the expiring list. Write-off of '
                            .rtrim(rtrim(number_format($deductQty, 3, '.', ''), '0'), '.')
                            .' submitted for approval.',
                    ];
                }

                $damage = Damage::create([
                    ...$damageData,
                    'reported_by' => $user->id,
                ]);

                $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
                $ledgerData = $this->withProductUnitCost([
                    'branch_id' => (int) $damage->branch_id,
                    'product_code' => (string) $damage->product_code,
                    'stock_location' => (string) $damage->stock_location,
                    'transaction_type' => 'DAMAGE',
                    'reference_type' => 'damage',
                    'reference_id' => $damage->id,
                    'quantity_change' => -abs($deductQty),
                    'notes' => $reason,
                    'created_by' => $user->id,
                ], (int) $user->organization_id);

                $this->postStockLedger($ledgerData, $allowBelowStock);

                $this->postInventoryMovementJournal(
                    $user,
                    $gate,
                    InventoryMovementJournalService::MOVEMENT_SHRINKAGE,
                    $deductQty,
                    isset($ledgerData['unit_cost']) ? (float) $ledgerData['unit_cost'] : null,
                    'DMG-'.$damage->id,
                    'Expiry clear #'.$receipt->id,
                    (int) $damage->branch_id,
                    'damage',
                    (int) $damage->id,
                    (string) $damage->product_code,
                );

                app(OperationalAuditService::class)->logStockMovement($user, 'damage', [
                    'damage_id' => (int) $damage->id,
                    'stock_receipt_id' => (int) $receipt->id,
                    'product_code' => (string) $damage->product_code,
                    'branch_id' => (int) $damage->branch_id,
                    'stock_location' => (string) $damage->stock_location,
                    'quantity' => $deductQty,
                    'source' => 'expiry_clear',
                ]);
            }

            $receipt->update([
                'expiry_cleared_at' => now(),
                'expiry_cleared_by' => $user->id,
                'expiry_cleared_qty' => $deductQty,
                'expiry_clear_reason' => $reason,
                'expiry_clear_damage_id' => $damage?->id,
            ]);

            $message = $deductQty > 0.0001
                ? 'Cleared batch and wrote off '
                    .rtrim(rtrim(number_format($deductQty, 3, '.', ''), '0'), '.')
                    .' from stock.'
                : 'Cleared batch from the expiring list (no stock left to write off).';

            return [
                'receipt' => $this->mapRowFresh($receipt->fresh(), Carbon::today()->toDateString()),
                'damage' => $damage,
                'message' => $message,
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Product>  $products
     * @param  array<string, CurrentStock>  $stockByKey
     * @param  array<string, array{supplier_id: int, supplier_name: string|null}>  $supplierByInvoice
     * @return array<string, mixed>
     */
    protected function mapRow(
        StockReceipt $receipt,
        string $today,
        $products,
        array $stockByKey,
        array $supplierByInvoice,
    ): array {
        $codeKey = strtolower((string) $receipt->product_code);
        $product = $products->get($codeKey);
        $stock = $stockByKey[$codeKey.':'.(int) $receipt->branch_id] ?? null;
        $location = in_array((string) $receipt->stock_location, ['shop', 'store'], true)
            ? (string) $receipt->stock_location
            : 'store';
        $onHand = $stock
            ? ($location === 'store' ? (float) $stock->store_quantity : (float) $stock->shop_quantity)
            : 0.0;

        $expiry = $receipt->expiry_date?->format('Y-m-d');
        $days = $expiry !== null
            ? (int) Carbon::parse($today)->diffInDays(Carbon::parse($expiry), false)
            : null;
        $status = $receipt->expiry_cleared_at
            ? 'cleared'
            : ($days !== null && $days < 0 ? 'expired' : 'expiring');

        $invoiceKey = strtolower(trim((string) ($receipt->invoice_number ?? '')));
        $supplier = $invoiceKey !== '' ? ($supplierByInvoice[$invoiceKey] ?? null) : null;

        return [
            'id' => (int) $receipt->id,
            'product_code' => (string) $receipt->product_code,
            'product_name' => $product?->product_name,
            'branch_id' => (int) $receipt->branch_id,
            'stock_location' => $location,
            'batch_no' => $receipt->batch_no,
            'expiry_date' => $expiry,
            'days_to_expiry' => $days,
            'status' => $status,
            'units_received' => round((float) $receipt->units_received, 3),
            'on_hand' => round($onHand, 3),
            'suggested_clear_qty' => round(min((float) $receipt->units_received, max(0, $onHand)), 3),
            'cost_price' => $receipt->cost_price !== null ? round((float) $receipt->cost_price, 2) : null,
            'invoice_number' => $receipt->invoice_number,
            'supplier_id' => $supplier['supplier_id'] ?? null,
            'supplier_name' => $supplier['supplier_name'] ?? null,
            'received_at' => $receipt->created_at,
            'cleared_at' => $receipt->expiry_cleared_at,
            'cleared_qty' => $receipt->expiry_cleared_qty !== null
                ? round((float) $receipt->expiry_cleared_qty, 3)
                : null,
            'clear_reason' => $receipt->expiry_clear_reason,
            'clear_damage_id' => $receipt->expiry_clear_damage_id
                ? (int) $receipt->expiry_clear_damage_id
                : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function mapRowFresh(StockReceipt $receipt, string $today): array
    {
        $products = Product::query()
            ->where('organization_id', (int) $receipt->organization_id)
            ->where('product_code', $receipt->product_code)
            ->get(['product_code', 'product_name', 'unit_id'])
            ->keyBy(fn ($p) => strtolower((string) $p->product_code));

        $stockByKey = [];
        $stock = CurrentStock::query()
            ->where('product_code', $receipt->product_code)
            ->where('branch_id', (int) $receipt->branch_id)
            ->first();
        if ($stock) {
            $stockByKey[strtolower((string) $receipt->product_code).':'.(int) $receipt->branch_id] = $stock;
        }

        $invoiceNos = [trim((string) ($receipt->invoice_number ?? ''))];
        $suppliers = $this->suppliersByInvoiceNumber((int) $receipt->organization_id, array_filter($invoiceNos));

        return $this->mapRow($receipt, $today, $products, $stockByKey, $suppliers);
    }

    /**
     * @param  list<string>  $invoiceNos
     * @return array<string, array{supplier_id: int, supplier_name: string|null}>
     */
    protected function suppliersByInvoiceNumber(int $organizationId, array $invoiceNos): array
    {
        if ($invoiceNos === []) {
            return [];
        }

        $map = [];
        $invoices = LpoSupplierInvoice::query()
            ->whereIn('supplier_invoice_number', $invoiceNos)
            ->whereHas('lpo', fn ($lpo) => $lpo->where('organization_id', $organizationId))
            ->with(['supplier:id,supplier_name'])
            ->orderByDesc('id')
            ->get(['id', 'supplier_id', 'supplier_invoice_number']);

        foreach ($invoices as $inv) {
            $key = strtolower(trim((string) $inv->supplier_invoice_number));
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $map[$key] = [
                'supplier_id' => (int) $inv->supplier_id,
                'supplier_name' => $inv->supplier?->supplier_name,
            ];
        }

        return $map;
    }
}
