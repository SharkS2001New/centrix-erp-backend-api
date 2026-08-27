<?php

namespace App\Services\Investors;

use App\Models\Expense;
use App\Models\Investor;
use App\Models\InvestorContribution;
use App\Models\InvestorProductBatch;
use App\Models\InvestorSpendLink;
use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestorService
{
    public function createInvestor(int $orgId, array $data, ?int $userId = null): Investor
    {
        $code = trim((string) ($data['investor_code'] ?? ''));
        if ($code === '') {
            $code = Investor::generateNextCode($orgId);
        }

        return Investor::query()->create([
            'organization_id' => $orgId,
            'branch_id' => $data['branch_id'] ?? null,
            'investor_code' => $code,
            'investor_name' => trim((string) $data['investor_name']),
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => ($data['is_active'] ?? true) !== false,
            'created_by' => $userId,
        ]);
    }

    public function updateInvestor(Investor $investor, array $data): Investor
    {
        $investor->fill(collect($data)->only([
            'branch_id',
            'investor_name',
            'contact_person',
            'phone',
            'email',
            'notes',
            'is_active',
        ])->all());
        if (isset($data['investor_code']) && trim((string) $data['investor_code']) !== '') {
            $investor->investor_code = trim((string) $data['investor_code']);
        }
        $investor->save();

        return $investor->fresh();
    }

    public function recordContribution(Investor $investor, array $data, ?int $userId = null): InvestorContribution
    {
        $type = strtolower(trim((string) ($data['contribution_type'] ?? '')));
        if (! in_array($type, [InvestorContribution::TYPE_CASH, InvestorContribution::TYPE_STOCK], true)) {
            throw ValidationException::withMessages([
                'contribution_type' => ['Contribution type must be cash or stock.'],
            ]);
        }

        return InvestorContribution::query()->create([
            'organization_id' => (int) $investor->organization_id,
            'investor_id' => (int) $investor->id,
            'branch_id' => $data['branch_id'] ?? $investor->branch_id,
            'contribution_type' => $type,
            'contribution_date' => $data['contribution_date'] ?? now()->toDateString(),
            'amount' => round((float) ($data['amount'] ?? 0), 2),
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'payment_code' => $data['payment_code'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'lpo_no' => $data['lpo_no'] ?? null,
            'supplier_payment_id' => $data['supplier_payment_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * Link a stock contribution to an LPO and/or supplier payment (by payment code).
     */
    public function linkContributionPayment(InvestorContribution $contribution, array $data): InvestorContribution
    {
        if ($contribution->contribution_type !== InvestorContribution::TYPE_STOCK) {
            throw ValidationException::withMessages([
                'contribution_id' => ['Only stock contributions can be linked to LPO / supplier payments.'],
            ]);
        }

        $orgId = (int) $contribution->organization_id;

        if (! empty($data['supplier_payment_id'])) {
            $payment = SupplierPayment::query()
                ->where('organization_id', $orgId)
                ->where('id', (int) $data['supplier_payment_id'])
                ->firstOrFail();
            $contribution->supplier_payment_id = (int) $payment->id;
            $contribution->supplier_id = $contribution->supplier_id ?: $payment->supplier_id;
            $contribution->lpo_no = $contribution->lpo_no ?: $payment->lpo_no;
            if (! $contribution->payment_code && $payment->reference_number) {
                $contribution->payment_code = $payment->reference_number;
            }
            if ((float) $contribution->amount <= 0) {
                $contribution->amount = (float) $payment->amount_paid;
            }
        } elseif (! empty($data['payment_code'])) {
            $code = trim((string) $data['payment_code']);
            $payment = SupplierPayment::query()
                ->where('organization_id', $orgId)
                ->where(function ($q) use ($code) {
                    $q->where('reference_number', $code)
                        ->orWhere('cheque_number', $code);
                })
                ->orderByDesc('id')
                ->first();
            if (! $payment) {
                throw ValidationException::withMessages([
                    'payment_code' => ['No supplier payment found for that payment code / reference.'],
                ]);
            }
            $contribution->payment_code = $code;
            $contribution->supplier_payment_id = (int) $payment->id;
            $contribution->supplier_id = $contribution->supplier_id ?: $payment->supplier_id;
            $contribution->lpo_no = $contribution->lpo_no ?: $payment->lpo_no;
            if ((float) $contribution->amount <= 0) {
                $contribution->amount = (float) $payment->amount_paid;
            }
        }

        if (! empty($data['lpo_no'])) {
            $contribution->lpo_no = (int) $data['lpo_no'];
        }

        $contribution->save();

        return $contribution->fresh(['supplier', 'supplierPayment', 'batches']);
    }

    /**
     * Allocate products to a contribution (creates investor stock batches).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, InvestorProductBatch>
     */
    public function allocateProducts(InvestorContribution $contribution, array $lines): Collection
    {
        $orgId = (int) $contribution->organization_id;
        $investorId = (int) $contribution->investor_id;
        $created = collect();

        DB::transaction(function () use ($contribution, $lines, $orgId, $investorId, &$created) {
            foreach ($lines as $line) {
                $productCode = trim((string) ($line['product_code'] ?? ''));
                if ($productCode === '') {
                    continue;
                }
                $qty = round((float) ($line['qty_purchased'] ?? $line['quantity'] ?? 0), 4);
                if ($qty <= 0) {
                    continue;
                }
                $unitCost = round((float) ($line['unit_cost'] ?? $line['cost_price'] ?? 0), 4);
                $product = Product::query()
                    ->where('organization_id', $orgId)
                    ->where('product_code', $productCode)
                    ->first();

                $batch = InvestorProductBatch::query()->create([
                    'organization_id' => $orgId,
                    'investor_id' => $investorId,
                    'contribution_id' => (int) $contribution->id,
                    'product_code' => $productCode,
                    'product_name' => $line['product_name']
                        ?? $product?->product_name
                        ?? $productCode,
                    'packaging' => $line['packaging'] ?? null,
                    'qty_purchased' => $qty,
                    'qty_remaining' => $qty,
                    'unit_cost' => $unitCost,
                    'lpo_no' => $line['lpo_no'] ?? $contribution->lpo_no,
                    'lpo_txn_id' => $line['lpo_txn_id'] ?? null,
                    'stock_receipt_id' => $line['stock_receipt_id'] ?? null,
                    'received_at' => $line['received_at']
                        ?? $contribution->contribution_date?->toDateString()
                        ?? now()->toDateString(),
                ]);
                $created->push($batch);
            }
        });

        return $created;
    }

    /** Import LPO lines onto a stock contribution as batches. */
    public function allocateFromLpo(InvestorContribution $contribution, int $lpoNo): Collection
    {
        $orgId = (int) $contribution->organization_id;
        $lines = LpoTxn::query()
            ->where('lpo_no', $lpoNo)
            ->whereHas('lpo', fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lpo_no' => ['No LPO lines found for that purchase order.'],
            ]);
        }

        $contribution->lpo_no = $lpoNo;
        $contribution->save();

        $payload = $lines->map(function (LpoTxn $txn) use ($orgId) {
            $qty = (float) ($txn->received_qty ?: $txn->ordered_qty ?: 0);
            $product = Product::query()
                ->where('organization_id', $orgId)
                ->where('product_code', $txn->product_code)
                ->first();

            return [
                'product_code' => $txn->product_code,
                'product_name' => $product?->product_name ?? $txn->product_code,
                'qty_purchased' => $qty,
                'unit_cost' => (float) ($txn->cost_price ?? 0),
                'lpo_no' => $txn->lpo_no,
                'lpo_txn_id' => $txn->getKey(),
            ];
        })->all();

        return $this->allocateProducts($contribution, $payload);
    }

    public function recordSpend(Investor $investor, array $data, ?int $userId = null): InvestorSpendLink
    {
        $type = strtolower(trim((string) ($data['spend_type'] ?? InvestorSpendLink::TYPE_OTHER)));
        $hasExplicitAmount = array_key_exists('amount', $data)
            && $data['amount'] !== null
            && $data['amount'] !== '';
        $amount = $hasExplicitAmount ? round((float) $data['amount'], 2) : 0.0;

        $label = $data['reference_label'] ?? null;
        $referenceId = isset($data['reference_id']) ? (int) $data['reference_id'] : null;
        $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        $lpoNo = isset($data['lpo_no']) ? (int) $data['lpo_no'] : null;
        $orgId = (int) $investor->organization_id;

        if ($type === InvestorSpendLink::TYPE_SUPPLIER_PAYMENT && $referenceId) {
            $payment = SupplierPayment::query()
                ->with('supplier:id,supplier_name,supplier_code')
                ->where('organization_id', $orgId)
                ->where('id', $referenceId)
                ->firstOrFail();
            $supplierId = (int) $payment->supplier_id;
            $lpoNo = $payment->lpo_no ? (int) $payment->lpo_no : null;
            $label = $label ?: $this->supplierPaymentSpendLabel($payment);
            if (! $hasExplicitAmount) {
                $amount = round((float) $payment->amount_paid, 2);
            }
        }

        if ($type === InvestorSpendLink::TYPE_SUPPLIER_PAYMENT && ! $referenceId) {
            if ($lpoNo) {
                $lpo = LpoMst::query()
                    ->with('supplier:id,supplier_name,supplier_code')
                    ->where('organization_id', $orgId)
                    ->where('lpo_no', $lpoNo)
                    ->first();
                if (! $lpo) {
                    throw ValidationException::withMessages([
                        'lpo_no' => ['The selected LPO was not found for this organization.'],
                    ]);
                }
                $supplierId = (int) ($lpo->supplier_id ?: $supplierId);
                if (! $label) {
                    $supplierName = $lpo->supplier?->supplier_name;
                    $label = 'LPO #'.$lpoNo.($supplierName ? ' · '.$supplierName : '');
                }
            } elseif ($supplierId) {
                $supplier = Supplier::query()
                    ->where('organization_id', $orgId)
                    ->where('id', $supplierId)
                    ->first();
                if (! $supplier) {
                    throw ValidationException::withMessages([
                        'supplier_id' => ['The selected supplier was not found for this organization.'],
                    ]);
                }
                $label = $label ?: ('Supplier payment · '.$supplier->supplier_name);
            } else {
                throw ValidationException::withMessages([
                    'lpo_no' => ['Choose an LPO or a supplier so this spend can be traced across payments.'],
                ]);
            }
        }

        if ($supplierId && $type === InvestorSpendLink::TYPE_SUPPLIER_PAYMENT && ! $referenceId) {
            $supplierOk = Supplier::query()
                ->where('organization_id', $orgId)
                ->where('id', $supplierId)
                ->exists();
            if (! $supplierOk) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['The selected supplier was not found for this organization.'],
                ]);
            }
        }

        if ($type === InvestorSpendLink::TYPE_EXPENSE && $referenceId) {
            $expense = Expense::query()
                ->where('organization_id', $orgId)
                ->where('id', $referenceId)
                ->firstOrFail();
            $label = $label ?: ('Expense: '.($expense->description ?: '#'.$expense->id));
            if (! $hasExplicitAmount) {
                $amount = round((float) $expense->expense_amount, 2);
            }
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Spend amount must be greater than zero.'],
            ]);
        }

        return InvestorSpendLink::query()->create([
            'organization_id' => $orgId,
            'investor_id' => (int) $investor->id,
            'contribution_id' => $data['contribution_id'] ?? null,
            'spend_type' => $type,
            'reference_id' => $referenceId,
            'reference_label' => $label,
            'supplier_id' => $supplierId,
            'lpo_no' => $lpoNo,
            'amount' => $amount,
            'spend_date' => $data['spend_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ])->load(['supplier:id,supplier_name,supplier_code']);
    }

    protected function supplierPaymentSpendLabel(SupplierPayment $payment): string
    {
        $parts = ['Supplier payment #'.$payment->id];
        if ($payment->reference_number) {
            $parts[] = (string) $payment->reference_number;
        }
        if ($payment->lpo_no) {
            $parts[] = 'LPO #'.$payment->lpo_no;
        }
        if ($payment->supplier?->supplier_name) {
            $parts[] = $payment->supplier->supplier_name;
        }

        return implode(' · ', $parts);
    }

    /**
     * Recalculate qty_remaining on batches using FIFO sales after each batch received_at.
     * Shared SKUs: only this investor's batches are consumed by chronological sales allocation
     * across ALL investors' batches for that product (true FIFO warehouse), then we only
     * update this investor's remaining from their share.
     *
     * Simpler org rule (per plan): within each product, sales consume investor batches
     * FIFO by received_at across investors; each batch's remaining is updated.
     */
    public function syncBatchRemainders(int $organizationId, ?int $investorId = null): void
    {
        $batchQuery = InvestorProductBatch::query()->where('organization_id', $organizationId);
        if ($investorId) {
            $batchQuery->where('investor_id', $investorId);
        }
        $batches = $batchQuery->orderBy('received_at')->orderBy('id')->get();
        if ($batches->isEmpty()) {
            return;
        }

        foreach ($batches->groupBy('product_code') as $productCode => $productBatches) {
            /** @var Collection<int, InvestorProductBatch> $productBatches */
            $earliest = $productBatches->min('received_at');
            $salesQty = (float) SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.organization_id', $organizationId)
                ->where('sale_items.product_code', $productCode)
                ->where('sales.archived', 0)
                ->whereNotIn('sales.status', ['cancelled', 'expired', 'held', 'draft'])
                ->when($earliest, fn ($q) => $q->whereDate('sales.created_at', '>=', Carbon::parse($earliest)->toDateString()))
                ->sum('sale_items.quantity');

            $remainingToAllocate = $salesQty;
            foreach ($productBatches->sortBy([
                ['received_at', 'asc'],
                ['id', 'asc'],
            ]) as $batch) {
                $purchased = (float) $batch->qty_purchased;
                $soldFromBatch = min($purchased, max(0, $remainingToAllocate));
                $batch->qty_remaining = round($purchased - $soldFromBatch, 4);
                $batch->save();
                $remainingToAllocate -= $soldFromBatch;
            }
        }
    }

    /** @return array<string, mixed> */
    public function accountSummary(Investor $investor): array
    {
        $this->syncBatchRemainders((int) $investor->organization_id, (int) $investor->id);

        $cashIn = (float) $investor->contributions()
            ->where('contribution_type', InvestorContribution::TYPE_CASH)
            ->sum('amount');
        $stockIn = (float) $investor->contributions()
            ->where('contribution_type', InvestorContribution::TYPE_STOCK)
            ->sum('amount');
        $spent = (float) $investor->spendLinks()->sum('amount');
        $stockValue = (float) $investor->batches()->get()->sum(fn (InvestorProductBatch $b) => $b->stockValue());

        return [
            'cash_contributed' => round($cashIn, 2),
            'stock_contributed' => round($stockIn, 2),
            'total_contributed' => round($cashIn + $stockIn, 2),
            'cash_spent' => round($spent, 2),
            'cash_pool_balance' => round($cashIn - $spent, 2),
            'stock_value' => round($stockValue, 2),
            'open_batches' => $investor->batches()->where('qty_remaining', '>', 0)->count(),
        ];
    }

    /**
     * Investor sales report (PDF-style): lines + paid/unpaid + profit after expenses.
     *
     * @return array<string, mixed>
     */
    public function salesReport(Investor $investor, ?string $fromDate = null, ?string $toDate = null): array
    {
        $orgId = (int) $investor->organization_id;
        $this->syncBatchRemainders($orgId, (int) $investor->id);

        $batches = $investor->batches()->orderBy('received_at')->orderBy('id')->get();
        $productCodes = $batches->pluck('product_code')->unique()->filter()->values()->all();
        if ($productCodes === []) {
            return [
                'investor' => $this->investorPayload($investor),
                'lines' => [],
                'totals' => $this->emptySalesTotals(),
                'expenses' => 0,
                'net_profit' => 0,
            ];
        }

        $itemsQuery = SaleItem::query()
            ->select([
                'sale_items.*',
                'sales.order_num',
                'sales.payment_status',
                'sales.status as sale_status',
                'sales.created_at as sale_created_at',
                'sales.amount_paid',
                'sales.order_total',
            ])
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.organization_id', $orgId)
            ->whereIn('sale_items.product_code', $productCodes)
            ->where('sales.archived', 0)
            ->whereNotIn('sales.status', ['cancelled', 'expired', 'held', 'draft'])
            ->orderBy('sales.created_at')
            ->orderBy('sale_items.id');

        if ($fromDate) {
            $itemsQuery->whereDate('sales.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $itemsQuery->whereDate('sales.created_at', '<=', $toDate);
        }

        $saleItems = $itemsQuery->get();

        // FIFO attribute sales qty to this investor's batches only.
        $batchPools = $batches->groupBy('product_code')->map(function (Collection $group) {
            return $group->map(fn (InvestorProductBatch $b) => [
                'batch' => $b,
                'left' => (float) $b->qty_purchased,
            ])->values();
        });

        $lines = [];
        $totalSales = 0.0;
        $totalCost = 0.0;
        $byPayment = ['paid' => 0.0, 'partial' => 0.0, 'unpaid' => 0.0];

        foreach ($saleItems as $item) {
            $code = (string) $item->product_code;
            $qty = (float) $item->quantity;
            if ($qty <= 0 || ! isset($batchPools[$code])) {
                continue;
            }

            $pools = $batchPools[$code];
            $attributed = 0.0;
            $costValue = 0.0;
            foreach ($pools as $idx => $pool) {
                if ($pool['left'] <= 0 || $attributed >= $qty) {
                    continue;
                }
                $take = min($pool['left'], $qty - $attributed);
                $costValue += $take * (float) $pool['batch']->unit_cost;
                $pools[$idx]['left'] = $pool['left'] - $take;
                $attributed += $take;
            }
            $batchPools[$code] = $pools;

            if ($attributed <= 0) {
                continue;
            }

            $lineTotal = (float) ($item->amount ?? 0);
            if ($lineTotal <= 0) {
                $lineTotal = (float) ($item->selling_price ?? 0) * $attributed;
            } elseif ($attributed < $qty) {
                $lineTotal = $lineTotal * ($attributed / $qty);
            }

            $profit = $lineTotal - $costValue;
            $paymentStatus = strtolower((string) ($item->payment_status ?? 'unpaid'));
            if (! in_array($paymentStatus, ['paid', 'partial', 'unpaid'], true)) {
                $paymentStatus = 'unpaid';
            }
            $byPayment[$paymentStatus] = ($byPayment[$paymentStatus] ?? 0) + $lineTotal;

            $lines[] = [
                'sale_id' => (int) $item->sale_id,
                'order_num' => $item->order_num,
                'invoice_label' => $item->order_num ? 'INV.S'.str_pad((string) $item->order_num, 4, '0', STR_PAD_LEFT) : 'SALE#'.$item->sale_id,
                'product_code' => $code,
                'product_name' => $item->product_name ?: $code,
                'quantity_sold' => round($attributed, 4),
                'unit_cost' => round($attributed > 0 ? $costValue / $attributed : 0, 4),
                'unit_price' => round($attributed > 0 ? $lineTotal / $attributed : 0, 4),
                'sales_value' => round($lineTotal, 2),
                'cost_value' => round($costValue, 2),
                'profit' => round($profit, 2),
                'payment_status' => $paymentStatus,
                'sale_date' => Carbon::parse($item->sale_created_at)->toDateString(),
            ];

            $totalSales += $lineTotal;
            $totalCost += $costValue;
        }

        $expenses = (float) $investor->spendLinks()
            ->when($fromDate, fn ($q) => $q->whereDate('spend_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('spend_date', '<=', $toDate))
            ->sum('amount');

        $grossProfit = $totalSales - $totalCost;
        $netProfit = $grossProfit - $expenses;

        return [
            'investor' => $this->investorPayload($investor),
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'lines' => $lines,
            'totals' => [
                'sales_value' => round($totalSales, 2),
                'cost_value' => round($totalCost, 2),
                'gross_profit' => round($grossProfit, 2),
                'expenses' => round($expenses, 2),
                'net_profit' => round($netProfit, 2),
                'paid_sales' => round($byPayment['paid'] ?? 0, 2),
                'partial_sales' => round($byPayment['partial'] ?? 0, 2),
                'unpaid_sales' => round($byPayment['unpaid'] ?? 0, 2),
            ],
            'expenses' => round($expenses, 2),
            'net_profit' => round($netProfit, 2),
        ];
    }

    /** @return array<string, mixed> */
    public function stockReport(Investor $investor): array
    {
        $this->syncBatchRemainders((int) $investor->organization_id, (int) $investor->id);
        $batches = $investor->batches()->orderBy('product_code')->orderBy('received_at')->get();

        $rows = $batches->map(function (InvestorProductBatch $b) {
            $sold = (float) $b->qty_purchased - (float) $b->qty_remaining;

            return [
                'batch_id' => $b->id,
                'contribution_id' => $b->contribution_id,
                'product_code' => $b->product_code,
                'product_name' => $b->product_name,
                'packaging' => $b->packaging,
                'qty_purchased' => (float) $b->qty_purchased,
                'qty_sold' => round($sold, 4),
                'qty_remaining' => (float) $b->qty_remaining,
                'unit_cost' => (float) $b->unit_cost,
                'stock_value' => $b->stockValue(),
                'received_at' => $b->received_at?->toDateString(),
                'lpo_no' => $b->lpo_no,
            ];
        })->values()->all();

        return [
            'investor' => $this->investorPayload($investor),
            'rows' => $rows,
            'totals' => [
                'qty_purchased' => round(collect($rows)->sum('qty_purchased'), 4),
                'qty_remaining' => round(collect($rows)->sum('qty_remaining'), 4),
                'stock_value' => round(collect($rows)->sum('stock_value'), 2),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function moneyFlowReport(Investor $investor, ?string $fromDate = null, ?string $toDate = null): array
    {
        $events = [];

        foreach ($investor->contributions()->orderBy('contribution_date')->orderBy('id')->get() as $c) {
            if ($fromDate && $c->contribution_date?->toDateString() < $fromDate) {
                continue;
            }
            if ($toDate && $c->contribution_date?->toDateString() > $toDate) {
                continue;
            }
            $events[] = [
                'date' => $c->contribution_date?->toDateString(),
                'type' => 'contribution_'.$c->contribution_type,
                'label' => strtoupper($c->contribution_type).' in'
                    .($c->payment_code ? ' ('.$c->payment_code.')' : ''),
                'in' => (float) $c->amount,
                'out' => 0.0,
                'reference_id' => $c->id,
            ];
        }

        foreach ($investor->spendLinks()->orderBy('spend_date')->orderBy('id')->get() as $s) {
            if ($fromDate && $s->spend_date?->toDateString() < $fromDate) {
                continue;
            }
            if ($toDate && $s->spend_date?->toDateString() > $toDate) {
                continue;
            }
            $events[] = [
                'date' => $s->spend_date?->toDateString(),
                'type' => 'spend_'.$s->spend_type,
                'label' => $s->reference_label ?: ('Spend: '.$s->spend_type),
                'in' => 0.0,
                'out' => (float) $s->amount,
                'reference_id' => $s->id,
            ];
        }

        $sales = $this->salesReport($investor, $fromDate, $toDate);
        $cashFromSales = (float) ($sales['totals']['paid_sales'] ?? 0)
            + (float) ($sales['totals']['partial_sales'] ?? 0);

        if ($cashFromSales > 0) {
            $events[] = [
                'date' => $toDate ?: now()->toDateString(),
                'type' => 'sales_collections',
                'label' => 'Cash collected from investor product sales (paid + partial)',
                'in' => $cashFromSales,
                'out' => 0.0,
                'reference_id' => null,
            ];
        }

        usort($events, function ($a, $b) {
            $cmp = strcmp((string) $a['date'], (string) $b['date']);

            return $cmp !== 0 ? $cmp : strcmp((string) $a['type'], (string) $b['type']);
        });

        $running = 0.0;
        foreach ($events as &$event) {
            $running += (float) $event['in'] - (float) $event['out'];
            $event['balance'] = round($running, 2);
        }
        unset($event);

        $stock = $this->stockReport($investor);
        $summary = $this->accountSummary($investor);

        return [
            'investor' => $this->investorPayload($investor),
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'events' => $events,
            'summary' => array_merge($summary, [
                'sales_value' => $sales['totals']['sales_value'] ?? 0,
                'gross_profit' => $sales['totals']['gross_profit'] ?? 0,
                'net_profit' => $sales['totals']['net_profit'] ?? 0,
                'stock_value' => $stock['totals']['stock_value'] ?? 0,
                'unpaid_sales' => $sales['totals']['unpaid_sales'] ?? 0,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    protected function investorPayload(Investor $investor): array
    {
        return [
            'id' => $investor->id,
            'investor_code' => $investor->investor_code,
            'investor_name' => $investor->investor_name,
        ];
    }

    /** @return array<string, float> */
    protected function emptySalesTotals(): array
    {
        return [
            'sales_value' => 0,
            'cost_value' => 0,
            'gross_profit' => 0,
            'expenses' => 0,
            'net_profit' => 0,
            'paid_sales' => 0,
            'partial_sales' => 0,
            'unpaid_sales' => 0,
        ];
    }
}
