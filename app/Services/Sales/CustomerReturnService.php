<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\InventoryTransaction;
use App\Models\Organization;
use App\Models\ReturnRecord;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\ReturnJournalService;
use App\Services\Erp\CapabilityGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerReturnService
{
    use HandlesInventory;

    public function __construct(
        protected CreditNoteService $creditNoteService,
        protected ReturnJournalService $returnJournal,
    ) {}

    public function nextReturnNo(int $organizationId): string
    {
        $last = CustomerReturn::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('return_no');

        $next = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'RET-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): CustomerReturn
    {
        return DB::transaction(function () use ($user, $data) {
            $saleId = isset($data['sale_id']) ? (int) $data['sale_id'] : null;
            if ($saleId) {
                $sale = Sale::query()->find($saleId);
                if ($sale && ($sale->fulfillment_meta['legacy_import'] ?? false)) {
                    throw ValidationException::withMessages([
                        'sale_id' => 'Use legacy returns for materialized legacy orders.',
                    ]);
                }
            }
            $lines = $this->normalizeLines($data['lines'] ?? [], $saleId);
            $total = round(array_sum(array_column($lines, 'amount')), 2);

            $return = CustomerReturn::create([
                'return_no' => $this->nextReturnNo((int) $user->organization_id),
                'organization_id' => $user->organization_id,
                'branch_id' => (int) ($data['branch_id'] ?? $user->branch_id),
                'sale_id' => $saleId,
                'customer_num' => $data['customer_num'] ?? null,
                'return_date' => $data['return_date'] ?? now()->toDateString(),
                'refund_method' => $data['refund_method'] ?? 'CASH',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'total_amount' => $total,
                'stock_location' => $data['stock_location']
                    ?? $this->inferDefaultReturnStockLocation($saleId, $user, $lines),
                'returned_by' => $user->id,
            ]);

            $this->syncLines($return, $lines);

            if (! empty($data['auto_approve'])) {
                return $this->approve($return->fresh(['lines']), $user);
            }

            return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser']);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(CustomerReturn $return, array $data): CustomerReturn
    {
        if ($return->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending returns can be edited.',
            ]);
        }

        return DB::transaction(function () use ($return, $data) {
            $saleId = isset($data['sale_id']) ? (int) $data['sale_id'] : (int) ($return->sale_id ?? 0);
            $lines = isset($data['lines'])
                ? $this->normalizeLines($data['lines'], $saleId ?: null, $return->id)
                : null;
            $total = $lines !== null ? round(array_sum(array_column($lines, 'amount')), 2) : null;

            $return->update(array_filter([
                'sale_id' => array_key_exists('sale_id', $data) ? $data['sale_id'] : $return->sale_id,
                'customer_num' => array_key_exists('customer_num', $data) ? $data['customer_num'] : $return->customer_num,
                'return_date' => $data['return_date'] ?? $return->return_date,
                'refund_method' => $data['refund_method'] ?? $return->refund_method,
                'reason' => array_key_exists('reason', $data) ? $data['reason'] : $return->reason,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $return->notes,
                'stock_location' => $data['stock_location'] ?? $return->stock_location,
                'total_amount' => $total,
            ], fn ($v) => $v !== null));

            if ($lines !== null) {
                $return->lines()->delete();
                $this->syncLines($return, $lines);
            }

            return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser']);
        });
    }

    public function approve(CustomerReturn $return, User $user): CustomerReturn
    {
        if ($return->status === 'approved') {
            return $return;
        }

        if ($return->status === 'rejected') {
            throw ValidationException::withMessages([
                'status' => 'Rejected returns cannot be approved.',
            ]);
        }

        return DB::transaction(function () use ($return, $user) {
            $return->load(['lines', 'sale.items']);

            if ($return->sale_id) {
                $this->validateLinesAgainstSale(
                    (int) $return->sale_id,
                    $return->lines->map(fn ($line) => [
                        'sale_item_id' => $line->sale_item_id,
                        'product_code' => $line->product_code,
                        'quantity_sold' => $line->quantity_sold,
                        'return_qty' => $line->return_qty,
                    ])->all(),
                    $return->id,
                );
            }

            foreach ($return->lines as $line) {
                if ((float) $line->return_qty <= 0) {
                    continue;
                }

                $this->postStockLedger([
                    'branch_id' => $return->branch_id,
                    'product_code' => $line->product_code,
                    'stock_location' => $this->resolveReturnStockLocation($return, $line, $user),
                    'transaction_type' => 'RETURN',
                    'reference_type' => 'customer_return',
                    'reference_id' => $return->id,
                    'quantity_change' => abs((float) $line->return_qty),
                    'created_by' => $user->id,
                ]);

                $legacy = ReturnRecord::create([
                    'sale_id' => $return->sale_id,
                    'branch_id' => $return->branch_id,
                    'product_code' => $line->product_code,
                    'quantity' => (float) $line->return_qty,
                    'uom' => $line->uom,
                    'amount' => (float) $line->amount,
                    'reason' => $return->reason,
                    'return_type' => 'PREVIOUS',
                    'returned_by' => $user->id,
                ]);

                $line->update(['legacy_return_id' => $legacy->id]);
            }

            if ($return->sale_id) {
                $this->applyReturnToSale($return->fresh(['lines']));
            }

            $return->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'reject_reason' => null,
            ]);

            $return = $return->fresh(['lines', 'sale', 'customer']);
            $organization = Organization::find($user->organization_id);
            $gate = $organization
                ? (new CapabilityGate($organization))
                : null;
            $finance = $gate?->moduleSettings('finance') ?? [];
            $this->creditNoteService->createForReturn($return, $user, $finance);

            if ($gate) {
                $this->returnJournal->postIfEnabled($return->fresh(['sale']), $user, $gate);
            }

            return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser', 'approvedByUser', 'creditNote']);
        });
    }

    public function reject(CustomerReturn $return, User $user, ?string $reason = null): CustomerReturn
    {
        if ($return->status === 'rejected') {
            return $return;
        }

        if ($return->status === 'approved') {
            return DB::transaction(function () use ($return, $user, $reason) {
                if ($return->sale_id) {
                    $this->reverseReturnFromSale($return);
                }
                $this->reverseApprovedStock($return, $user);
                $this->deleteLegacySyncRows($return);
                CreditNote::query()->where('customer_return_id', $return->id)->delete();

                $return->update([
                    'status' => 'rejected',
                    'rejected_by' => $user->id,
                    'rejected_at' => now(),
                    'reject_reason' => $reason,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);

                return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser', 'rejectedByUser']);
            });
        }

        $return->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'reject_reason' => $reason,
        ]);

        return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser', 'rejectedByUser']);
    }

    public function deleteReturn(CustomerReturn $return, User $user): void
    {
        if ($return->status === 'approved') {
            DB::transaction(function () use ($return, $user) {
                if ($return->sale_id) {
                    $this->reverseReturnFromSale($return);
                }
                $this->reverseApprovedStock($return, $user);
                $this->deleteLegacySyncRows($return);
                CreditNote::query()->where('customer_return_id', $return->id)->delete();
                $return->lines()->delete();
                $return->delete();
            });

            return;
        }

        $return->lines()->delete();
        $return->delete();
    }

    public function linesFromSale(Sale $sale, ?string $returnKind = 'standard'): array
    {
        $sale->loadMissing(['items.product.unit']);
        $returned = $this->approvedReturnQuantities((int) $sale->id, null, $returnKind);

        return $sale->items->map(function ($item) use ($returned, $sale, $returnKind) {
            $currentQty = (float) ($item->quantity ?? 0);
            $alreadyReturned = $this->returnedQtyForLine($returned, (int) $item->id, (string) $item->product_code);
            $originalQty = $currentQty + $alreadyReturned;
            $unitPrice = $currentQty > 0
                ? round((float) $item->amount / $currentQty, 2)
                : round((float) ($item->selling_price ?? 0), 2);
            $pending = $this->pendingReturnQuantities((int) $sale->id, null, $returnKind);
            $pendingQty = $this->returnedQtyForLine($pending, (int) $item->id, (string) $item->product_code);
            $maxReturnQty = max(0, round($currentQty - $pendingQty, 4));

            return [
                'sale_item_id' => $item->id,
                'product_code' => $item->product_code,
                'product_name' => $item->product?->product_name ?? $item->product_code,
                'uom' => $item->uom ?? $item->product?->unit?->uom_type ?? null,
                'product' => $item->product,
                'quantity_sold' => $originalQty,
                'already_returned' => $alreadyReturned,
                'max_return_qty' => $maxReturnQty,
                'return_qty' => 0,
                'unit_price' => round($unitPrice, 2),
                'amount' => 0,
                'line_no' => $item->line_no,
            ];
        })->values()->all();
    }

    public function applyReturnToSalePublic(CustomerReturn $return): void
    {
        $this->applyReturnToSale($return);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function normalizeLinesForSale(
        array $lines,
        ?int $saleId = null,
        ?int $excludeReturnId = null,
        ?string $returnKind = 'legacy',
    ): array {
        return $this->normalizeLines($lines, $saleId, $excludeReturnId, $returnKind);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function syncLinesPublic(CustomerReturn $return, array $lines): void
    {
        $this->syncLines($return, $lines);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function validateLinesAgainstSalePublic(
        int $saleId,
        array $lines,
        ?int $excludeReturnId = null,
        ?string $returnKind = 'legacy',
    ): void {
        $this->validateLinesAgainstSale($saleId, $lines, $excludeReturnId, $returnKind);
    }

    protected function applyReturnToSale(CustomerReturn $return): void
    {
        $return->loadMissing(['lines']);
        $sale = Sale::with('items')->find($return->sale_id);
        if (! $sale) {
            return;
        }

        $totalReturnAmount = 0.0;
        $totalVatReduction = 0.0;
        $totalDiscountReduction = 0.0;

        foreach ($return->lines as $line) {
            $returnQty = (float) $line->return_qty;
            if ($returnQty <= 0) {
                continue;
            }

            $saleItem = $this->findSaleItemForReturnLine($sale, $line);
            if (! $saleItem) {
                continue;
            }

            $currentQty = (float) $saleItem->quantity;
            $returnAmount = (float) $line->amount;
            $vatReduction = $this->proportionalShare((float) ($saleItem->product_vat ?? 0), $returnQty, $currentQty);
            $discountReduction = $this->proportionalShare((float) ($saleItem->discount_given ?? 0), $returnQty, $currentQty);

            $saleItem->update([
                'quantity' => max(0, round($currentQty - $returnQty, 4)),
                'amount' => max(0, round((float) $saleItem->amount - $returnAmount, 2)),
                'product_vat' => max(0, round((float) ($saleItem->product_vat ?? 0) - $vatReduction, 2)),
                'discount_given' => max(0, round((float) ($saleItem->discount_given ?? 0) - $discountReduction, 2)),
            ]);

            $totalReturnAmount += $returnAmount;
            $totalVatReduction += $vatReduction;
            $totalDiscountReduction += $discountReduction;
        }

        $sale->update([
            'order_total' => max(0, round((float) $sale->order_total - $totalReturnAmount, 2)),
            'total_vat' => max(0, round((float) ($sale->total_vat ?? 0) - $totalVatReduction, 2)),
            'order_discount' => max(0, round((float) ($sale->order_discount ?? 0) - $totalDiscountReduction, 2)),
        ]);

        $this->syncSalePaymentAfterReturn($sale->fresh(), $totalReturnAmount);
    }

    protected function reverseReturnFromSale(CustomerReturn $return): void
    {
        $return->loadMissing(['lines']);
        $sale = Sale::with('items')->find($return->sale_id);
        if (! $sale) {
            return;
        }

        $totalReturnAmount = 0.0;
        $totalVatReduction = 0.0;
        $totalDiscountReduction = 0.0;

        foreach ($return->lines as $line) {
            $returnQty = (float) $line->return_qty;
            if ($returnQty <= 0) {
                continue;
            }

            $saleItem = $this->findSaleItemForReturnLine($sale, $line);
            if (! $saleItem) {
                continue;
            }

            $returnAmount = (float) $line->amount;
            $currentQty = (float) $saleItem->quantity;
            $vatAdd = $currentQty > 0
                ? $this->proportionalShare((float) ($saleItem->product_vat ?? 0), $returnQty, $currentQty)
                : 0.0;
            $discountAdd = $currentQty > 0
                ? $this->proportionalShare((float) ($saleItem->discount_given ?? 0), $returnQty, $currentQty)
                : 0.0;

            $saleItem->update([
                'quantity' => round($currentQty + $returnQty, 4),
                'amount' => round((float) $saleItem->amount + $returnAmount, 2),
                'product_vat' => round((float) ($saleItem->product_vat ?? 0) + $vatAdd, 2),
                'discount_given' => round((float) ($saleItem->discount_given ?? 0) + $discountAdd, 2),
            ]);

            $totalReturnAmount += $returnAmount;
            $totalVatReduction += $vatAdd;
            $totalDiscountReduction += $discountAdd;
        }

        $sale->update([
            'order_total' => round((float) $sale->order_total + $totalReturnAmount, 2),
            'total_vat' => round((float) ($sale->total_vat ?? 0) + $totalVatReduction, 2),
            'order_discount' => round((float) ($sale->order_discount ?? 0) + $totalDiscountReduction, 2),
        ]);

        $this->syncSalePaymentAfterReturnReversal($sale->fresh(), $totalReturnAmount);
    }

    protected function findSaleItemForReturnLine(Sale $sale, CustomerReturnLine $line): ?SaleItem
    {
        if ($line->sale_item_id) {
            $item = $sale->items->firstWhere('id', $line->sale_item_id);
            if ($item) {
                return $item;
            }
        }

        return $sale->items->firstWhere('product_code', $line->product_code);
    }

    protected function proportionalShare(float $total, float $part, float $of): float
    {
        if ($of <= 0 || $part <= 0 || $total <= 0) {
            return 0.0;
        }

        return round($total * ($part / $of), 2);
    }

    protected function syncSalePaymentAfterReturn(Sale $sale, float $returnAmount): void
    {
        if ($returnAmount <= 0) {
            return;
        }

        $orderTotal = (float) $sale->order_total;
        $amountPaid = (float) ($sale->amount_paid ?? 0);
        $newAmountPaid = max(0, round($amountPaid - $returnAmount, 2));

        $sale->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $this->paymentStatusForAmounts($orderTotal, $newAmountPaid, $sale->payment_status),
        ]);
    }

    protected function syncSalePaymentAfterReturnReversal(Sale $sale, float $returnAmount): void
    {
        if ($returnAmount <= 0) {
            return;
        }

        $orderTotal = (float) $sale->order_total;
        $amountPaid = min($orderTotal, round((float) ($sale->amount_paid ?? 0) + $returnAmount, 2));

        $sale->update([
            'amount_paid' => $amountPaid,
            'payment_status' => $this->paymentStatusForAmounts($orderTotal, $amountPaid, $sale->payment_status),
        ]);
    }

    protected function paymentStatusForAmounts(float $orderTotal, float $amountPaid, ?string $current): string
    {
        if ($orderTotal <= 0) {
            return 'paid';
        }
        if ($amountPaid <= 0) {
            return 'unpaid';
        }
        if ($amountPaid + 0.009 >= $orderTotal) {
            return 'paid';
        }

        return 'partial';
    }

    /**
     * @return array{by_sale_item: array<int, float>, by_product: array<string, float>}
     */
    protected function pendingReturnQuantities(int $saleId, ?int $excludeReturnId = null, ?string $returnKind = null): array
    {
        $query = CustomerReturnLine::query()
            ->select(['customer_return_lines.sale_item_id', 'customer_return_lines.product_code', 'customer_return_lines.return_qty'])
            ->join('customer_returns', 'customer_returns.id', '=', 'customer_return_lines.customer_return_id')
            ->where('customer_returns.sale_id', $saleId)
            ->where('customer_returns.status', 'pending');

        if ($returnKind) {
            $query->where('customer_returns.return_kind', $returnKind);
        }

        if ($excludeReturnId) {
            $query->where('customer_returns.id', '!=', $excludeReturnId);
        }

        $bySaleItem = [];
        $byProduct = [];

        foreach ($query->get() as $line) {
            $qty = (float) $line->return_qty;
            if ($line->sale_item_id) {
                $bySaleItem[(int) $line->sale_item_id] = ($bySaleItem[(int) $line->sale_item_id] ?? 0) + $qty;
            }
            $code = (string) $line->product_code;
            $byProduct[$code] = ($byProduct[$code] ?? 0) + $qty;
        }

        return ['by_sale_item' => $bySaleItem, 'by_product' => $byProduct];
    }

    protected function maxReturnQtyForSaleItem(
        SaleItem $saleItem,
        int $saleId,
        ?int $excludeReturnId = null,
        ?string $returnKind = null,
    ): float {
        $pending = $this->pendingReturnQuantities($saleId, $excludeReturnId, $returnKind);
        $pendingQty = $this->returnedQtyForLine(
            $pending,
            (int) $saleItem->id,
            (string) $saleItem->product_code,
        );

        return max(0, round((float) $saleItem->quantity - $pendingQty, 4));
    }

    protected function reverseApprovedStock(CustomerReturn $return, User $user): void
    {
        $return->load(['lines', 'sale.items']);

        foreach ($return->lines as $line) {
            if ((float) $line->return_qty <= 0) {
                continue;
            }

            $this->postStockLedger([
                'branch_id' => $return->branch_id,
                'product_code' => $line->product_code,
                'stock_location' => $this->resolveReturnStockLocation($return, $line, $user),
                'transaction_type' => 'RETURN',
                'reference_type' => 'customer_return_reversal',
                'reference_id' => $return->id,
                'quantity_change' => -abs((float) $line->return_qty),
                'created_by' => $user->id,
            ], true);
        }
    }

    /** Restore stock to the same location the original sale line deducted from. */
    protected function resolveReturnStockLocation(
        CustomerReturn $return,
        CustomerReturnLine $line,
        User $user,
    ): string {
        if ($return->sale_id) {
            $sale = $return->relationLoaded('sale') && $return->sale
                ? $return->sale
                : Sale::with('items')->find($return->sale_id);

            if ($sale) {
                $sale->loadMissing('items');
                $gate = $this->capabilityGateForUser($user);
                if ($gate) {
                    $inventory = $gate->moduleSettings('inventory');
                    $sales = $gate->moduleSettings('sales');
                    $saleItem = $line->sale_item_id
                        ? $sale->items->firstWhere('id', (int) $line->sale_item_id)
                        : null;
                    $saleItem ??= $sale->items->firstWhere('product_code', $line->product_code);

                    if ($saleItem) {
                        return $this->saleLineStockLocation(
                            (string) $sale->channel,
                            $inventory,
                            $sales,
                            (bool) $saleItem->on_wholesale_retail,
                        );
                    }
                }

                $ledgerLocation = InventoryTransaction::query()
                    ->where('reference_type', 'sale')
                    ->where('reference_id', $sale->id)
                    ->where('product_code', $line->product_code)
                    ->where('quantity_change', '<', 0)
                    ->value('stock_location');

                if ($ledgerLocation) {
                    return (string) $ledgerLocation;
                }
            }
        }

        return (string) ($return->stock_location ?? 'shop');
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function inferDefaultReturnStockLocation(?int $saleId, User $user, array $lines): string
    {
        if (! $saleId || $lines === []) {
            return 'shop';
        }

        $sale = Sale::with('items')->find($saleId);
        if (! $sale) {
            return 'shop';
        }

        $gate = $this->capabilityGateForUser($user);
        if (! $gate) {
            return 'shop';
        }

        $inventory = $gate->moduleSettings('inventory');
        $sales = $gate->moduleSettings('sales');
        $locations = [];

        foreach ($lines as $lineData) {
            $saleItem = ! empty($lineData['sale_item_id'])
                ? $sale->items->firstWhere('id', (int) $lineData['sale_item_id'])
                : null;
            $saleItem ??= $sale->items->firstWhere(
                'product_code',
                (string) ($lineData['product_code'] ?? ''),
            );

            if ($saleItem) {
                $locations[] = $this->saleLineStockLocation(
                    (string) $sale->channel,
                    $inventory,
                    $sales,
                    (bool) $saleItem->on_wholesale_retail,
                );
            }
        }

        $unique = array_values(array_unique($locations));

        return $unique[0] ?? 'shop';
    }

    protected function capabilityGateForUser(User $user): ?CapabilityGate
    {
        $organization = Organization::find($user->organization_id);

        return $organization ? new CapabilityGate($organization) : null;
    }

    protected function deleteLegacySyncRows(CustomerReturn $return): void
    {
        $return->load('lines');
        $legacyIds = $return->lines
            ->pluck('legacy_return_id')
            ->filter()
            ->unique()
            ->values();

        if ($legacyIds->isNotEmpty()) {
            ReturnRecord::query()->whereIn('id', $legacyIds)->delete();

            return;
        }

        // Fallback for returns approved before legacy_return_id tracking existed.
        ReturnRecord::query()
            ->where('branch_id', $return->branch_id)
            ->where('sale_id', $return->sale_id)
            ->whereIn('product_code', $return->lines->pluck('product_code'))
            ->delete();
    }

    /**
     * @return array{by_sale_item: array<int, float>, by_product: array<string, float>}
     */
    protected function approvedReturnQuantities(int $saleId, ?int $excludeReturnId = null, ?string $returnKind = null): array
    {
        $query = CustomerReturnLine::query()
            ->select(['customer_return_lines.sale_item_id', 'customer_return_lines.product_code', 'customer_return_lines.return_qty'])
            ->join('customer_returns', 'customer_returns.id', '=', 'customer_return_lines.customer_return_id')
            ->where('customer_returns.sale_id', $saleId)
            ->where('customer_returns.status', 'approved');

        if ($returnKind) {
            $query->where('customer_returns.return_kind', $returnKind);
        }

        if ($excludeReturnId) {
            $query->where('customer_returns.id', '!=', $excludeReturnId);
        }

        $bySaleItem = [];
        $byProduct = [];

        foreach ($query->get() as $line) {
            $qty = (float) $line->return_qty;
            if ($line->sale_item_id) {
                $bySaleItem[(int) $line->sale_item_id] = ($bySaleItem[(int) $line->sale_item_id] ?? 0) + $qty;
            }
            $code = (string) $line->product_code;
            $byProduct[$code] = ($byProduct[$code] ?? 0) + $qty;
        }

        return ['by_sale_item' => $bySaleItem, 'by_product' => $byProduct];
    }

    protected function returnedQtyForLine(array $returned, ?int $saleItemId, string $productCode): float
    {
        if ($saleItemId && isset($returned['by_sale_item'][$saleItemId])) {
            return (float) $returned['by_sale_item'][$saleItemId];
        }

        return (float) ($returned['by_product'][$productCode] ?? 0);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function validateLinesAgainstSale(
        int $saleId,
        array $lines,
        ?int $excludeReturnId = null,
        ?string $returnKind = null,
    ): void {
        $sale = Sale::with('items')->findOrFail($saleId);

        foreach ($lines as $line) {
            $returnQty = (float) ($line['return_qty'] ?? 0);
            if ($returnQty <= 0) {
                continue;
            }

            $saleItem = null;
            if (! empty($line['sale_item_id'])) {
                $saleItem = $sale->items->firstWhere('id', (int) $line['sale_item_id']);
            }
            $saleItem ??= $sale->items->firstWhere('product_code', (string) $line['product_code']);

            if (! $saleItem) {
                throw ValidationException::withMessages([
                    'lines' => "Product {$line['product_code']} was not found on this order.",
                ]);
            }

            $maxReturnQty = $this->maxReturnQtyForSaleItem($saleItem, $saleId, $excludeReturnId, $returnKind);

            if ($returnQty > $maxReturnQty + 0.0001) {
                throw ValidationException::withMessages([
                    'lines' => "Return quantity for {$line['product_code']} exceeds remaining returnable quantity ({$maxReturnQty}).",
                ]);
            }
        }
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function normalizeLines(
        array $lines,
        ?int $saleId = null,
        ?int $excludeReturnId = null,
        ?string $returnKind = null,
    ): array {
        $normalized = [];

        foreach ($lines as $line) {
            $returnQty = (float) ($line['return_qty'] ?? 0);
            if ($returnQty <= 0) {
                continue;
            }

            $qtySold = (float) ($line['quantity_sold'] ?? $returnQty);
            $maxReturnQty = isset($line['max_return_qty'])
                ? (float) $line['max_return_qty']
                : $qtySold;

            if ($returnQty > $maxReturnQty + 0.0001) {
                throw ValidationException::withMessages([
                    'lines' => "Return quantity cannot exceed remaining returnable quantity for {$line['product_code']}.",
                ]);
            }

            if ($returnQty > $qtySold + 0.0001) {
                throw ValidationException::withMessages([
                    'lines' => "Return quantity cannot exceed quantity sold for {$line['product_code']}.",
                ]);
            }

            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $amount = round((float) ($line['amount'] ?? ($returnQty * $unitPrice)), 2);

            $normalized[] = [
                'sale_item_id' => $line['sale_item_id'] ?? null,
                'product_code' => (string) $line['product_code'],
                'product_name' => $line['product_name'] ?? null,
                'uom' => $line['uom'] ?? null,
                'quantity_sold' => $qtySold,
                'return_qty' => $returnQty,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'line_no' => $line['line_no'] ?? null,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product with a return quantity.',
            ]);
        }

        if ($saleId) {
            $this->validateLinesAgainstSale($saleId, $normalized, $excludeReturnId, $returnKind);
        }

        return $normalized;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function syncLines(CustomerReturn $return, array $lines): void
    {
        foreach ($lines as $line) {
            CustomerReturnLine::create([
                'customer_return_id' => $return->id,
                ...$line,
            ]);
        }
    }
}
