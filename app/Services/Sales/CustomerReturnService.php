<?php

namespace App\Services\Sales;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ReturnRecord;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\ReturnJournalService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Fulfillment\TripAutoCloseService;
use App\Services\Returns\ReturnProofService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerReturnService
{
    use HandlesInventory;

    public function __construct(
        protected CreditNoteService $creditNoteService,
        protected ReturnJournalService $returnJournal,
        protected UserPermissionService $permissions,
        protected ReturnProofService $proofService,
        protected CustomerReturnNumberAllocator $returnNumbers,
        protected SaleLineQuantityDisplayService $lineQuantityDisplay,
    ) {}

    public function withActionFlags(CustomerReturn $return, User $user): CustomerReturn
    {
        $canManage = (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'sales.manage');
        $pending = $return->status === 'pending';
        $approved = $return->status === 'approved';

        $return->setAttribute('can_edit', $pending && $canManage);
        $return->setAttribute('can_delete', $canManage);
        $return->setAttribute('can_approve', $pending && $canManage);
        $return->setAttribute('can_reject', ($pending || $approved) && $canManage);
        $return->setAttribute('can_print', true);
        $return->setAttribute(
            'proof',
            $this->proofService->meta($return, '/customer-returns/'.$return->id.'/proof/file'),
        );

        return $return;
    }

    /** @deprecated Use CustomerReturnNumberAllocator inside a transaction. */
    public function nextReturnNo(int $organizationId): string
    {
        return $this->returnNumbers->formatReturnNo(
            $this->returnNumbers->nextForOrganization($organizationId),
        );
    }

    protected function allocateReturnDocument(int $organizationId): array
    {
        $sequence = $this->returnNumbers->nextForOrganization($organizationId);

        return [
            'return_seq' => $sequence,
            'return_no' => $this->returnNumbers->formatReturnNo($sequence),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data, ?UploadedFile $proof = null): CustomerReturn
    {
        return DB::transaction(function () use ($user, $data, $proof) {
            $saleId = isset($data['sale_id']) ? (int) $data['sale_id'] : null;
            if ($saleId) {
                $this->assertSaleEligibleForCustomerReturn($saleId, $user);
            }
            $lines = $this->normalizeLines($data['lines'] ?? [], $saleId);
            $total = round(array_sum(array_column($lines, 'amount')), 2);

            $document = $this->allocateReturnDocument((int) $user->organization_id);

            $return = CustomerReturn::create([
                ...$document,
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

            if ($proof !== null) {
                $this->proofService->store(
                    $return,
                    $proof,
                    \App\Support\OrganizationPublicStorage::path($return->organization_id ?? $user->organization_id, 'returns', 'customer', (string) $return->id),
                );
                $return->refresh();
            }

            if (! empty($data['auto_approve'])) {
                return $this->approve($return->fresh(['lines']), $user);
            }

            $return = $return->fresh(['lines', 'sale', 'customer', 'returnedByUser']);
            app(CustomerReturnApprovalService::class)->notifyOnCreate($user, $return);

            return $return;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(CustomerReturn $return, array $data, ?UploadedFile $proof = null): CustomerReturn
    {
        if ($return->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending returns can be edited.',
            ]);
        }

        return DB::transaction(function () use ($return, $data, $proof) {
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

            if ($proof !== null) {
                $this->proofService->store(
                    $return,
                    $proof,
                    \App\Support\OrganizationPublicStorage::path($return->organization_id ?? $user->organization_id, 'returns', 'customer', (string) $return->id),
                );
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

                $unitCost = $this->resolveReturnUnitCost(
                    $return->sale_id ? (int) $return->sale_id : null,
                    (string) $line->product_code,
                    (int) $user->organization_id,
                );

                $this->postStockLedger([
                    'branch_id' => $return->branch_id,
                    'product_code' => $line->product_code,
                    'stock_location' => $this->resolveReturnStockLocation($return, $line, $user),
                    'transaction_type' => 'RETURN',
                    'reference_type' => 'customer_return',
                    'reference_id' => $return->id,
                    'quantity_change' => abs((float) $line->return_qty),
                    'unit_cost' => $unitCost,
                    'created_by' => $user->id,
                ]);

                $legacy = ReturnRecord::create([
                    'organization_id' => (int) $return->organization_id,
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
                $sale = Sale::query()->find($return->sale_id);
                if ($sale) {
                    app(TripAutoCloseService::class)->markReturnedSaleCompleteIfBalanced($sale, $user);
                }
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

            if ($return->sale_id) {
                $sale = Sale::query()->find($return->sale_id);
                if ($sale) {
                    app(CustomerInvoiceService::class)
                        ->preserveOriginalTotalAfterReturn($sale);
                }
            }

            if ($gate) {
                $this->returnJournal->postIfEnabled($return->fresh(['sale']), $user, $gate);
            }

            return $return->fresh(['lines', 'sale', 'customer', 'returnedByUser', 'approvedByUser', 'creditNote']);
        });
    }

    /**
     * Fiscal void for POS order edit: issue a KRA credit note without restocking inventory.
     * Stock is restored separately when the sale is cancelled for cart restore.
     */
    public function approvePosEditVoid(Sale $sale, User $user, CapabilityGate $gate): void
    {
        if (CustomerReturn::query()
            ->where('sale_id', $sale->id)
            ->where('return_kind', 'pos_edit')
            ->where('status', 'approved')
            ->exists()) {
            return;
        }

        $finance = $gate->moduleSettings('finance') ?? [];
        if (empty($finance['enable_kra_device'])) {
            return;
        }

        $sale->loadMissing(['items.product', 'customer']);
        $linePayloads = collect($this->linesFromSale($sale))
            ->filter(fn ($line) => (float) ($line['max_return_qty'] ?? 0) > 0)
            ->map(function ($line) {
                $returnQty = (float) $line['max_return_qty'];
                $maxReturnQty = (float) ($line['max_return_qty'] ?? $returnQty);
                $lineTotal = (float) ($line['line_total'] ?? 0);
                $amount = $this->returnAmountForQty($returnQty, $maxReturnQty, $lineTotal);

                return [
                    'sale_item_id' => $line['sale_item_id'],
                    'product_code' => $line['product_code'],
                    'product_name' => $line['product_name'],
                    'uom' => $line['uom'],
                    'quantity_sold' => $line['quantity_sold'],
                    'return_qty' => $returnQty,
                    'unit_price' => $line['unit_price'],
                    'amount' => $amount,
                    'line_no' => $line['line_no'],
                ];
            })
            ->values()
            ->all();

        if ($linePayloads === []) {
            return;
        }

        $total = round(array_sum(array_column($linePayloads, 'amount')), 2);

        DB::transaction(function () use ($user, $sale, $linePayloads, $total, $finance) {
            $document = $this->allocateReturnDocument((int) $user->organization_id);

            $return = CustomerReturn::create([
                ...$document,
                'organization_id' => $user->organization_id,
                'branch_id' => $sale->branch_id ?? $user->branch_id,
                'sale_id' => $sale->id,
                'customer_num' => $sale->customer_num,
                'return_date' => now()->toDateString(),
                'refund_method' => $sale->payment_method_code ?: 'CASH',
                'reason' => 'POS order edit void',
                'notes' => 'Automatic fiscal void before POS order edit.',
                'status' => 'approved',
                'total_amount' => $total,
                'stock_location' => $this->inferDefaultReturnStockLocation((int) $sale->id, $user, $linePayloads),
                'returned_by' => $user->id,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'return_kind' => 'pos_edit',
            ]);

            $this->syncLines($return, $linePayloads);
            $return = $return->fresh(['lines', 'sale', 'customer']);
            $this->creditNoteService->createForReturn($return, $user, $finance);
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
                $this->proofService->deleteExisting($return);
                $return->lines()->delete();
                $return->delete();
            });

            return;
        }

        $this->proofService->deleteExisting($return);
        $return->lines()->delete();
        $return->delete();
    }

    public function linesFromSale(Sale $sale, ?string $mode = null): array
    {
        $sale->loadMissing(['items.product.unit']);
        $returned = $this->approvedReturnQuantities((int) $sale->id);
        $legacy = $mode === 'legacy';

        return $sale->items->map(function ($item) use ($returned, $sale, $legacy) {
            $storedQty = (float) ($item->quantity ?? 0);
            $currentQty = $this->saleItemQuantityAsBase($item, $storedQty);
            $alreadyReturned = $this->returnedQtyForLine($returned, (int) $item->id, (string) $item->product_code);
            $originalQty = $currentQty + $alreadyReturned;
            $lineAmount = round((float) ($item->amount ?? 0), 2);
            $product = $item->product ?? new Product(['product_code' => $item->product_code]);
            $isRetail = (bool) ($item->on_wholesale_retail ?? false);
            // Match order presentation: sold/pack unit price, not amount ÷ base qty.
            $unitPrice = $this->lineQuantityDisplay->displayUnitPrice(
                $currentQty > 0 ? $currentQty : $originalQty,
                $lineAmount,
                $product,
                $isRetail,
                (float) ($item->discount_given ?? 0),
                (float) ($item->selling_price ?? 0),
                $item->display_unit_price !== null ? (float) $item->display_unit_price : null,
            );
            $pending = $this->pendingReturnQuantities((int) $sale->id);
            $pendingQty = $this->returnedQtyForLine($pending, (int) $item->id, (string) $item->product_code);
            $maxReturnQty = max(0, round($currentQty - $pendingQty, 4));
            $returnQty = $legacy ? $maxReturnQty : 0;
            $returnAmount = $legacy
                ? $this->legacyReturnAmountForSaleItem($lineAmount, $currentQty, $returnQty)
                : 0.0;

            $soldUom = trim((string) ($item->uom ?? ''));

            return [
                'sale_item_id' => $item->id,
                'product_code' => $item->product_code,
                'product_name' => $item->product?->product_name ?? $item->product_code,
                'uom' => $soldUom !== '' ? $soldUom : ($item->product?->unit?->uom_type ?? null),
                'sold_uom' => $soldUom !== '' ? $soldUom : null,
                'product' => $legacy ? null : $item->product,
                'quantity_sold' => $originalQty,
                'already_returned' => $alreadyReturned,
                'max_return_qty' => $maxReturnQty,
                'return_qty' => $returnQty,
                'unit_price' => round($unitPrice, 2),
                'line_total' => $lineAmount,
                'amount' => $returnAmount,
                'line_no' => $item->line_no,
                'on_wholesale_retail' => (int) ($item->on_wholesale_retail ?? 0),
                'display_uom_mode' => $legacy ? 'legacy' : 'centrix',
                'full_return' => $legacy,
            ];
        })->values()->all();
    }

    /**
     * Credit amount for a return qty. Prefer proportional share of the remaining
     * sale line total — unit_price is the sold/display pack price and must not
     * be multiplied by base-unit return_qty.
     */
    protected function returnAmountForQty(float $returnQty, float $maxReturnQty, float $lineTotal): float
    {
        if ($returnQty <= 0 || $lineTotal <= 0) {
            return 0.0;
        }

        if ($maxReturnQty <= 0) {
            return 0.0;
        }

        if ($returnQty + 0.0001 >= $maxReturnQty) {
            return round($lineTotal, 2);
        }

        return round($lineTotal * ($returnQty / $maxReturnQty), 2);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function normalizeLinesForSale(array $lines, int $saleId, ?string $mode = null): array
    {
        return $this->normalizeLines($lines, $saleId, null, $mode);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function syncLinesPublic(CustomerReturn $return, array $lines): void
    {
        $this->syncLines($return, $lines);
    }

    public function applyReturnToSalePublic(CustomerReturn $return): void
    {
        $this->applyReturnToSale($return);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function validateLinesAgainstSalePublic(
        int $saleId,
        array $lines,
        ?int $excludeReturnId = null,
        ?string $mode = null,
    ): void {
        $this->validateLinesAgainstSale($saleId, $lines, $excludeReturnId, $mode);
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

            $currentQty = $this->saleItemQuantityAsBase($saleItem, (float) $saleItem->quantity);
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
    protected function pendingReturnQuantities(int $saleId, ?int $excludeReturnId = null): array
    {
        $query = CustomerReturnLine::query()
            ->select(['customer_return_lines.sale_item_id', 'customer_return_lines.product_code', 'customer_return_lines.return_qty'])
            ->join('customer_returns', 'customer_returns.id', '=', 'customer_return_lines.customer_return_id')
            ->where('customer_returns.sale_id', $saleId)
            ->where('customer_returns.status', 'pending');

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

    protected function maxReturnQtyForSaleItem(SaleItem $saleItem, int $saleId, ?int $excludeReturnId = null): float
    {
        $pending = $this->pendingReturnQuantities($saleId, $excludeReturnId);
        $pendingQty = $this->returnedQtyForLine(
            $pending,
            (int) $saleItem->id,
            (string) $saleItem->product_code,
        );

        $baseQty = $this->saleItemQuantityAsBase($saleItem, (float) $saleItem->quantity);

        return max(0, round($baseQty - $pendingQty, 4));
    }

    /**
     * Sale line quantities must be base (smallest) units for returns and stock.
     * Some older lines stored pack/entry qty, or one pack's pieces while the
     * line amount reflects multiple packs — expand those to base for validation.
     */
    protected function saleItemQuantityAsBase(SaleItem $saleItem, float $storedQty): float
    {
        if ($storedQty <= 0) {
            return 0.0;
        }

        $saleItem->loadMissing('product.unit');
        $product = $saleItem->product;
        if (! $product || (bool) ($saleItem->on_wholesale_retail ?? false)) {
            return $storedQty;
        }

        $factor = max(1.0, (float) ($product->unit?->conversion_factor ?? 1));
        if ($factor <= 1) {
            return $storedQty;
        }

        $lineAmount = round((float) ($saleItem->amount ?? 0), 2);
        if ($lineAmount <= 0) {
            return $storedQty;
        }

        $displayPrice = $this->lineQuantityDisplay->displayUnitPrice(
            $storedQty,
            $lineAmount,
            $product,
            false,
            (float) ($saleItem->discount_given ?? 0),
            (float) ($saleItem->selling_price ?? 0),
            $saleItem->display_unit_price !== null ? (float) $saleItem->display_unit_price : null,
        );

        if ($displayPrice <= 0) {
            return $storedQty;
        }

        $impliedPacks = round(($lineAmount + max(0.0, (float) ($saleItem->discount_given ?? 0))) / $displayPrice, 4);
        if ($impliedPacks <= 0) {
            return $storedQty;
        }

        $impliedBase = round($impliedPacks * $factor, 4);

        // Already stored in base units (N packs × factor).
        if (abs($storedQty - $impliedBase) <= 0.05) {
            return $storedQty;
        }

        // Stored as pack/entry count (matches sold pack qty from money).
        if (abs($storedQty - $impliedPacks) <= 0.05) {
            return $impliedBase;
        }

        // Stored as a single pack in pieces while amount is for multiple packs
        // (e.g. qty=18 for an 18×500ml carton line priced as 10 cartons).
        if ($impliedPacks >= 1.999 && abs($storedQty - $factor) <= 0.05) {
            return $impliedBase;
        }

        return $storedQty;
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
                'unit_cost' => $this->resolveReturnUnitCost(
                    $return->sale_id ? (int) $return->sale_id : null,
                    (string) $line->product_code,
                    (int) $user->organization_id,
                ),
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
                $saleItem = $line->sale_item_id
                    ? $sale->items->firstWhere('id', (int) $line->sale_item_id)
                    : null;
                $saleItem ??= $sale->items->firstWhere('product_code', $line->product_code);

                if ($saleItem && $gate) {
                    return $this->resolveReturnStockLocationForSaleLine($sale, $saleItem, $user, $gate);
                }

                $ledgerLocation = $this->originalSaleDeductionTxn((int) $sale->id, (string) $line->product_code)
                    ?->stock_location;

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
                $locations[] = $this->resolveReturnStockLocationForSaleLine($sale, $saleItem, $user, $gate);
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
    protected function approvedReturnQuantities(int $saleId, ?int $excludeReturnId = null): array
    {
        $query = CustomerReturnLine::query()
            ->select(['customer_return_lines.sale_item_id', 'customer_return_lines.product_code', 'customer_return_lines.return_qty'])
            ->join('customer_returns', 'customer_returns.id', '=', 'customer_return_lines.customer_return_id')
            ->where('customer_returns.sale_id', $saleId)
            ->where('customer_returns.status', 'approved');

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
        ?string $mode = null,
    ): void {
        $sale = Sale::with('items')->findOrFail($saleId);
        $legacy = $mode === 'legacy';

        foreach ($lines as $line) {
            $returnQty = (float) ($line['return_qty'] ?? 0);
            if ($returnQty <= 0) {
                continue;
            }

            $saleItem = $this->findSaleItemForNormalizedLine($sale, $line);

            if (! $saleItem) {
                throw ValidationException::withMessages([
                    'lines' => "Product {$line['product_code']} was not found on this order.",
                ]);
            }

            $maxReturnQty = $this->maxReturnQtyForSaleItem($saleItem, $saleId, $excludeReturnId);

            if ($returnQty > $maxReturnQty + 0.0001) {
                $saleItem->loadMissing('product.unit');
                $product = $saleItem->product ?? new Product(['product_code' => $saleItem->product_code]);
                $remainingLabel = $this->lineQuantityDisplay->formatLineQtyDisplay(
                    $maxReturnQty,
                    $product,
                    (bool) ($saleItem->on_wholesale_retail ?? false),
                    trim((string) ($saleItem->uom ?? '')) ?: null,
                );

                throw ValidationException::withMessages([
                    'lines' => "Return quantity for {$line['product_code']} exceeds remaining returnable quantity ({$remainingLabel}).",
                ]);
            }

            if ($legacy) {
                $currentQty = (float) $saleItem->quantity;
                $maxAmount = round((float) ($saleItem->amount ?? 0), 2);
                $returnAmount = round((float) ($line['amount'] ?? 0), 2);
                $expectedAmount = $this->legacyReturnAmountForSaleItem(
                    $maxAmount,
                    $currentQty,
                    $returnQty,
                );

                if ($returnAmount > $expectedAmount + 0.02) {
                    throw ValidationException::withMessages([
                        'lines' => "Return amount for {$line['product_code']} exceeds the remaining line total ({$expectedAmount}).",
                    ]);
                }
            }
        }
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function normalizeLines(
        array $lines,
        ?int $saleId = null,
        ?int $excludeReturnId = null,
        ?string $mode = null,
    ): array {
        $legacy = $mode === 'legacy';
        $sale = $saleId ? Sale::with('items.product')->find($saleId) : null;
        $normalized = [];

        foreach ($lines as $line) {
            $returnQty = (float) ($line['return_qty'] ?? 0);
            if ($returnQty <= 0) {
                continue;
            }

            $saleItem = $sale ? $this->findSaleItemForNormalizedLine($sale, $line) : null;

            if ($legacy && $saleItem) {
                $currentQty = (float) $saleItem->quantity;
                $alreadyReturned = 0.0;
                if ($saleId) {
                    $approved = $this->approvedReturnQuantities($saleId, $excludeReturnId);
                    $alreadyReturned = $this->returnedQtyForLine(
                        $approved,
                        (int) $saleItem->id,
                        (string) $saleItem->product_code,
                    );
                }

                $qtySold = $currentQty + $alreadyReturned;
                $maxReturnQty = $this->maxReturnQtyForSaleItem($saleItem, (int) $saleId, $excludeReturnId);

                if ($returnQty > $maxReturnQty + 0.0001) {
                    throw ValidationException::withMessages([
                        'lines' => "Return quantity cannot exceed remaining returnable quantity for {$line['product_code']}.",
                    ]);
                }

                $amount = $this->legacyReturnAmountForSaleItem(
                    (float) ($saleItem->amount ?? 0),
                    $currentQty,
                    $returnQty,
                );
                $unitPrice = $returnQty > 0 ? round($amount / $returnQty, 2) : 0.0;

                $normalized[] = [
                    'sale_item_id' => $saleItem->id,
                    'product_code' => (string) $saleItem->product_code,
                    'product_name' => $line['product_name'] ?? $saleItem->product?->product_name ?? $saleItem->product_code,
                    'uom' => $saleItem->uom ?? $line['uom'] ?? null,
                    'quantity_sold' => $qtySold,
                    'return_qty' => $returnQty,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'line_no' => $line['line_no'] ?? $saleItem->line_no,
                ];

                continue;
            }

            $qtySold = (float) ($line['quantity_sold'] ?? $returnQty);
            $maxReturnQty = $saleItem
                ? $this->maxReturnQtyForSaleItem($saleItem, (int) $saleId, $excludeReturnId)
                : (isset($line['max_return_qty'])
                    ? (float) $line['max_return_qty']
                    : $qtySold);

            if ($returnQty > $maxReturnQty + 0.0001) {
                throw ValidationException::withMessages([
                    'lines' => "Return quantity cannot exceed remaining returnable quantity for {$line['product_code']}.",
                ]);
            }

            if ($saleItem) {
                $qtySold = $this->saleItemQuantityAsBase($saleItem, (float) ($saleItem->quantity ?? 0))
                    + ($saleId ? $this->returnedQtyForLine(
                        $this->approvedReturnQuantities((int) $saleId, $excludeReturnId),
                        (int) $saleItem->id,
                        (string) $saleItem->product_code,
                    ) : 0.0);
            }

            if ($returnQty > $qtySold + 0.0001) {
                throw ValidationException::withMessages([
                    'lines' => "Return quantity cannot exceed quantity sold for {$line['product_code']}.",
                ]);
            }

            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $lineTotal = (float) ($line['line_total'] ?? ($saleItem?->amount ?? 0));
            if (array_key_exists('amount', $line) && $line['amount'] !== null && $line['amount'] !== '') {
                $amount = round((float) $line['amount'], 2);
            } elseif ($saleItem) {
                $amount = $this->returnAmountForQty($returnQty, $maxReturnQty, round((float) ($saleItem->amount ?? 0), 2));
            } elseif ($lineTotal > 0 && $maxReturnQty > 0) {
                $amount = $this->returnAmountForQty($returnQty, $maxReturnQty, $lineTotal);
            } else {
                $amount = 0.0;
            }

            if ($saleItem && ($unitPrice <= 0 || ! array_key_exists('unit_price', $line))) {
                $product = $saleItem->product ?? new Product(['product_code' => $saleItem->product_code]);
                $unitPrice = $this->lineQuantityDisplay->displayUnitPrice(
                    (float) ($saleItem->quantity ?? 0),
                    round((float) ($saleItem->amount ?? 0), 2),
                    $product,
                    (bool) ($saleItem->on_wholesale_retail ?? false),
                    (float) ($saleItem->discount_given ?? 0),
                    (float) ($saleItem->selling_price ?? 0),
                    $saleItem->display_unit_price !== null ? (float) $saleItem->display_unit_price : null,
                );
            }

            $normalized[] = [
                'sale_item_id' => $line['sale_item_id'] ?? $saleItem?->id,
                'product_code' => (string) ($line['product_code'] ?? $saleItem?->product_code),
                'product_name' => $line['product_name'] ?? $saleItem?->product?->product_name,
                'uom' => $line['uom'] ?? $saleItem?->uom ?? null,
                'quantity_sold' => $qtySold,
                'return_qty' => $returnQty,
                'unit_price' => round($unitPrice, 2),
                'amount' => $amount,
                'line_no' => $line['line_no'] ?? $saleItem?->line_no,
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product with a return quantity.',
            ]);
        }

        if ($saleId) {
            $this->validateLinesAgainstSale($saleId, $normalized, $excludeReturnId, $mode);
        }

        return $normalized;
    }

    protected function legacyReturnAmountForSaleItem(
        float $lineAmount,
        float $currentQty,
        float $returnQty,
    ): float {
        if ($returnQty <= 0) {
            return 0.0;
        }

        if ($currentQty <= 0) {
            return round($lineAmount, 2);
        }

        if ($returnQty + 0.0001 >= $currentQty) {
            return round($lineAmount, 2);
        }

        return round($lineAmount * ($returnQty / $currentQty), 2);
    }

    /** @param  array<string, mixed>  $line */
    protected function findSaleItemForNormalizedLine(Sale $sale, array $line): ?SaleItem
    {
        if (! empty($line['sale_item_id'])) {
            $item = $sale->items->firstWhere('id', (int) $line['sale_item_id']);
            if ($item) {
                return $item;
            }
        }

        return $sale->items->firstWhere('product_code', (string) ($line['product_code'] ?? ''));
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

    protected function assertSaleEligibleForCustomerReturn(int $saleId, User $user): void
    {
        $sale = Sale::query()
            ->where('organization_id', $user->organization_id)
            ->find($saleId);

        if (! $sale) {
            throw ValidationException::withMessages([
                'sale_id' => 'Sale not found for this return.',
            ]);
        }

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($user);
        $workflow = \App\Services\Erp\OrderWorkflowService::forGate($gate);

        if (! $workflow->isCustomerReturnStatus((string) $sale->status, $sale->channel)) {
            throw ValidationException::withMessages([
                'sale_id' => 'Customer returns are not allowed for orders in this stage.',
            ]);
        }
    }
}
