<?php

namespace App\Services;

use App\Models\LpoMst;
use App\Models\LpoSupplierInvoice;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\SupplierReturn;
use App\Models\Uom;
use App\Support\LpoStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class LpoModuleService
{
    public function __construct(
        protected SupplierBalanceService $supplierBalances,
    ) {}

    public function formatPoNumber(int $lpoNo): string
    {
        return 'PO-' . $lpoNo;
    }

    public function index(Request $request): array
    {
        $query = LpoMst::query()->whereNull('deleted_at')->orderByDesc('created_at');

        if ($supplierId = $request->input('supplier_id')) {
            $query->where('supplier_id', (int) $supplierId);
        }
        if ($status = $request->input('status_code')) {
            $query->where('lpo_status_code', (int) $status);
        }
        if ($from = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($sub) use ($q) {
                if (ctype_digit($q)) {
                    $sub->orWhere('lpo_no', (int) $q);
                }
                $sub->orWhere('reference_number', 'like', "%{$q}%")
                    ->orWhere('supplier_invoice_no', 'like', "%{$q}%")
                    ->orWhereIn('supplier_id', function ($s) use ($q) {
                        $s->select('id')
                            ->from('suppliers')
                            ->whereNull('deleted_at')
                            ->where('supplier_name', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $paginated = $query->paginate($perPage);

        $lpoNos = collect($paginated->items())->pluck('lpo_no')->filter()->values();
        $supplierIds = collect($paginated->items())->pluck('supplier_id')->unique()->filter();
        $suppliers = Supplier::query()->whereIn('id', $supplierIds)->get()->keyBy('id');
        $statusNames = DB::table('lpo_statuses')->pluck('status_name', 'status_code');

        $paidByLpo = $lpoNos->isEmpty()
            ? collect()
            : SupplierPayment::query()
                ->whereIn('lpo_no', $lpoNos)
                ->selectRaw('lpo_no, SUM(amount_paid) as amount_paid')
                ->groupBy('lpo_no')
                ->pluck('amount_paid', 'lpo_no');

        $creatorIds = collect($paginated->items())->pluck('created_by')->unique()->filter();
        $creators = $creatorIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $creatorIds)->get()->keyBy('id');

        $paginated->getCollection()->transform(function (LpoMst $l) use ($suppliers, $statusNames, $paidByLpo, $creators) {
            return $this->mapListRow($l, $suppliers[$l->supplier_id] ?? null, $statusNames, $paidByLpo, $creators);
        });

        return $paginated->toArray();
    }

    public function dashboard(?int $organizationId = null): array
    {
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $query = LpoMst::query()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start, $end]);

        $rows = $query->get();
        $totalValue = round((float) $rows->sum(fn ($l) => (float) ($l->net_amount ?? $l->total_amount ?? 0)), 2);

        $pending = $rows->whereIn('lpo_status_code', [
            LpoStatus::AWAITING_CHECK,
            LpoStatus::AWAITING_APPROVAL,
            LpoStatus::AWAITING_SEND,
        ])->count();
        $partial = $rows->whereIn('lpo_status_code', [
            LpoStatus::AWAITING_RECEIVE,
            LpoStatus::AWAITING_LAST_RECEIVE,
        ])->count();
        $cleared = $rows->where(fn ($l) => (int) ($l->cleared_flag ?? 0) === 1
            || (int) $l->lpo_status_code === LpoStatus::CLEARED)->count();

        return [
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
            'total_pos' => $rows->count(),
            'total_value' => $totalValue,
            'pending_count' => $pending,
            'partially_received_count' => $partial,
            'cleared_count' => $cleared,
        ];
    }

    public function detail(int $lpoNo): array
    {
        $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->firstOrFail();
        $supplier = Supplier::query()->find($lpo->supplier_id);
        $statusNames = DB::table('lpo_statuses')->pluck('status_name', 'status_code');

        $lines = LpoTxn::query()
            ->where('lpo_no', $lpoNo)
            ->orderBy('id')
            ->get();

        $productCodes = $lines->pluck('product_code')->unique()->filter();
        $products = Product::query()
            ->whereIn('product_code', $productCodes)
            ->get()
            ->keyBy('product_code');

        $uoms = Uom::query()
            ->whereIn('id', $products->pluck('unit_id')->unique()->filter())
            ->get()
            ->keyBy('id');
        $vatPcts = DB::table('vats')->pluck('vat_percentage', 'id');

        $returnedApprovedByProduct = $this->returnedQtyByProduct($lpoNo);
        $returnedCommittedByProduct = $this->committedReturnQtyByProduct($lpoNo);

        $lineRows = $lines->map(function (LpoTxn $t) use ($products, $uoms, $vatPcts, $returnedApprovedByProduct, $returnedCommittedByProduct) {
            $product = $products[$t->product_code] ?? null;
            $uom = $product ? ($uoms[$product->unit_id] ?? null) : null;
            $ordered = (float) $t->ordered_qty;
            $received = (float) ($t->received_qty ?? 0);
            $returnedApproved = (float) ($returnedApprovedByProduct[$t->product_code] ?? 0);
            $committedReturn = (float) ($returnedCommittedByProduct[$t->product_code] ?? 0);
            $effectiveReceived = max(0, $received - $returnedApproved);
            $returnable = max(0, $received - $returnedApproved);
            $maxReturn = max(0, $ordered - $committedReturn);
            $remainingToReceive = max(0, $ordered - $received - $committedReturn);
            $cost = (float) ($t->cost_price ?? 0);
            $packaging = $this->formatPackagingLabel($uom) ?: ($t->uom ?? '—');

            $factor = $uom ? (float) ($uom->conversion_factor ?? 1) : 1;

            $receiveStatus = 'open';
            if ($committedReturn + 0.0001 >= $ordered) {
                $receiveStatus = 'fully_returned';
            } elseif ($remainingToReceive <= 0.0001 && $received > 0) {
                $receiveStatus = 'complete';
            } elseif ($received > 0) {
                $receiveStatus = 'partial';
            }

            $receivedByLocation = $this->receivedBaseQtyByLocation((int) $t->lpo_no, (int) $t->id);
            $receivedLocationOptions = $this->allowedReturnLocations($receivedByLocation);

            return [
                'id' => $t->id,
                'lpo_no' => (int) $t->lpo_no,
                'product_code' => $t->product_code,
                'product_name' => $product?->product_name ?? $t->product_code,
                'packaging_label' => $packaging,
                'conversion_factor' => $factor,
                'package_name' => $uom ? trim($uom->full_name ?: $uom->uom_type ?: '') : ($t->uom ?? ''),
                'measure_unit' => $uom ? $this->measureUnitLabel($uom->uom_type) : '',
                'uom' => $t->uom ?? ($uom?->full_name ?: $uom?->uom_type),
                'unit_id' => $product?->unit_id,
                'vat_rate' => $product ? (float) ($vatPcts[$product->vat_id] ?? 0) : 0,
                'ordered_qty' => $ordered,
                'received_qty' => $received,
                'returned_qty' => round($committedReturn, 3),
                'returned_qty_approved' => round($returnedApproved, 3),
                'committed_return_qty' => round($committedReturn, 3),
                'effective_received_qty' => round($effectiveReceived, 3),
                'returnable_qty' => round($returnable, 3),
                'max_return_qty' => round($maxReturn, 3),
                'remaining_qty' => round($remainingToReceive, 3),
                'cost_price' => $cost,
                'line_total' => round($ordered * $cost, 2),
                'received_line_total' => round($effectiveReceived * $cost, 2),
                'receive_status' => $receiveStatus,
                'received_qty_by_location' => $receivedByLocation,
                'received_stock_location' => $this->primaryReceivedLocation($receivedByLocation),
                'received_location_options' => $receivedLocationOptions,
            ];
        })->values();

        $invoices = LpoSupplierInvoice::query()
            ->where('lpo_no', $lpoNo)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'lpo_no' => (int) $inv->lpo_no,
                'supplier_id' => (int) $inv->supplier_id,
                'supplier_invoice_number' => $inv->supplier_invoice_number,
                'invoice_date' => $inv->invoice_date
                    ? Carbon::parse($inv->invoice_date)->format('Y-m-d')
                    : null,
                'invoice_amount' => (float) ($inv->invoice_amount ?? 0),
                'file_name' => $inv->file_name,
                'mime_type' => $inv->mime_type,
                'has_document' => (bool) $inv->file_path,
                'document_url' => $inv->file_path ? url('/storage/'.$inv->file_path) : null,
                'received_at' => $inv->created_at
                    ? Carbon::parse($inv->created_at)->format('Y-m-d H:i')
                    : null,
                'source' => 'invoice',
            ])
            ->values();

        $paid = (float) SupplierPayment::query()->where('lpo_no', $lpoNo)->sum('amount_paid');
        $total = (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0);
        $adjustedNet = $this->orderNetAfterReturns($lpo);
        $receivedPayable = $this->receivedPayableTotals($lpo);
        $balanceDue = $this->payableBalanceDue($lpo, $paid);

        $creators = $lpo->created_by
            ? User::query()->whereIn('id', [(int) $lpo->created_by])->get()->keyBy('id')
            : collect();
        $header = $this->mapListRow($lpo, $supplier, $statusNames, collect([$lpoNo => $paid]), $creators);
        $header['balance_due'] = $balanceDue;

        return [
            'lpo' => array_merge($header, [
                'received_payable_subtotal' => $receivedPayable['subtotal'],
                'received_payable_vat' => $receivedPayable['vat'],
                'received_payable_total' => $receivedPayable['total'],
                'adjusted_net_amount' => $adjustedNet,
                'return_credit_total' => round(max(0, $total - $adjustedNet), 2),
                'terms' => $lpo->terms,
                'instructions' => $lpo->instructions,
                'delivery_address' => $lpo->delivery_address,
                'vat_amount' => (float) ($lpo->vat_amount ?? 0),
                'email_sent_flag' => (int) ($lpo->email_sent_flag ?? 0),
                'sent_at' => $lpo->sent_at,
                'sent_by' => $lpo->sent_by,
                'cleared_at' => $lpo->cleared_at,
                'created_by' => $lpo->created_by,
                'supplier_phone' => $supplier?->phone ?? $supplier?->alternate_phone ?? null,
                'supplier_email' => $supplier?->email ?? null,
                'can_edit' => $this->canEdit($lpo),
                'can_delete' => $this->canDelete($lpo),
                'items_fully_received' => $this->itemsFullyReceived($lpo),
                'can_pay' => $this->canPay($lpo),
                'can_receive' => $this->canReceive($lpo),
                'can_create_return' => $this->canCreateReturn($lpo),
                'items_fully_returned_to_supplier' => $this->itemsFullyReturnedToSupplier($lpo),
                'workflow_actions' => $this->workflowActions($lpo, $balanceDue),
            ]),
            'lines' => $lineRows,
            'supplier_invoices' => $invoices,
            'supplier_returns' => $this->listSupplierReturns($lpoNo),
            'payments_total' => round($paid, 2),
            'balance_due' => $balanceDue,
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveWithLines(array $header, array $lines, int $userId, ?int $lpoNo = null): LpoMst
    {
        if (empty($header['supplier_id'])) {
            throw new InvalidArgumentException('Supplier is required.');
        }
        if (count($lines) === 0) {
            throw new InvalidArgumentException('Add at least one line item.');
        }

        return DB::transaction(function () use ($header, $lines, $userId, $lpoNo) {
            $totals = $this->computeTotals($lines);

            $payload = [
                'supplier_id' => (int) $header['supplier_id'],
                'reference_number' => $header['reference_number'] ?? null,
                'due_date' => $header['due_date'] ?? null,
                'delivery_address' => $header['delivery_address'] ?? null,
                'terms' => $header['terms'] ?? null,
                'instructions' => $header['instructions'] ?? null,
                'total_amount' => $totals['total'],
                'vat_amount' => $totals['vat'],
                'net_amount' => $totals['total'],
            ];

            if ($lpoNo) {
                $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->firstOrFail();
                if (! $this->canEdit($lpo)) {
                    throw new InvalidArgumentException(
                        'This LPO cannot be edited after it has been sent to the supplier.',
                    );
                }
                $lpo->update($payload);
                LpoTxn::query()->where('lpo_no', $lpoNo)->delete();
            } else {
                $payload['lpo_status_code'] = LpoStatus::AWAITING_CHECK;
                $payload['created_by'] = $userId;
                $payload['created_at'] = now();
                $lpo = LpoMst::create($payload);
                $lpoNo = (int) $lpo->lpo_no;
            }

            foreach ($lines as $line) {
                LpoTxn::create([
                    'lpo_no' => $lpoNo,
                    'product_code' => $line['product_code'],
                    'ordered_qty' => (float) $line['ordered_qty'],
                    'uom' => $line['uom'] ?? null,
                    'cost_price' => (float) ($line['cost_price'] ?? 0),
                    'received_qty' => (float) ($line['received_qty'] ?? 0),
                    'markup_amount' => $line['markup_amount'] ?? null,
                    'markup_percent' => $line['markup_percent'] ?? null,
                ]);
                $this->syncProductCostPrice($line, $userId);
            }

            $this->syncReceiveStatus($lpoNo);
            $this->supplierBalances->recalculate((int) $header['supplier_id']);

            return $lpo->fresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{subtotal: float, vat: float, total: float}
     */
    protected function computeTotals(array $lines): array
    {
        $codes = collect($lines)->pluck('product_code')->filter()->unique();
        $products = $codes->isEmpty()
            ? collect()
            : Product::query()->whereIn('product_code', $codes)->get()->keyBy('product_code');
        $vatPcts = DB::table('vats')->pluck('vat_percentage', 'id');

        $subtotal = 0.0;
        $vat = 0.0;
        foreach ($lines as $line) {
            $qty = (float) ($line['ordered_qty'] ?? 0);
            $cost = (float) ($line['cost_price'] ?? 0);
            $lineNet = $qty * $cost;
            $subtotal += $lineNet;
            $product = $products[$line['product_code']] ?? null;
            $vatPct = $product ? (float) ($vatPcts[$product->vat_id] ?? 0) : 0;
            $vat += $lineNet * ($vatPct / 100);
        }

        $subtotal = round($subtotal, 2);
        $vat = round($vat, 2);

        return ['subtotal' => $subtotal, 'vat' => $vat, 'total' => round($subtotal + $vat, 2)];
    }

    protected function syncProductCostPrice(array $line, int $userId): void
    {
        $code = $line['product_code'] ?? null;
        if (! $code || ! isset($line['cost_price'])) {
            return;
        }

        $cost = round((float) $line['cost_price'], 2);
        $product = Product::query()->where('product_code', $code)->whereNull('deleted_at')->first();
        if (! $product) {
            return;
        }

        if (round((float) ($product->last_cost_price ?? 0), 2) === $cost) {
            return;
        }

        $product->update([
            'last_cost_price' => $cost,
            'updated_by' => $userId,
        ]);
    }

    protected function formatPackagingLabel(?Uom $uom): ?string
    {
        if (! $uom) {
            return null;
        }

        $packageName = trim($uom->full_name ?: $uom->uom_type ?: 'package');
        $factor = $this->formatFactorDisplay((float) ($uom->conversion_factor ?? 1));

        return "{$packageName} ({$factor})";
    }

    protected function measureUnitLabel(?string $uomType): string
    {
        $t = strtolower(trim($uomType ?? 'units'));

        return match ($t) {
            'piece', 'pcs' => 'pieces',
            'carton' => 'cartons',
            'bag' => 'bags',
            'kg', 'kilogram' => 'kg',
            'g', 'gram' => 'g',
            'l', 'litre', 'liter' => 'litres',
            'ml' => 'ml',
            default => $uomType ?: 'units',
        };
    }

    protected function formatFactorDisplay(float $factor): string
    {
        $rounded = round($factor, 3);

        return abs($rounded - (int) $rounded) < 0.001
            ? (string) (int) $rounded
            : (string) $rounded;
    }

    public function itemsFullyReturnedToSupplier(LpoMst $lpo): bool
    {
        $lines = LpoTxn::query()->where('lpo_no', $lpo->lpo_no)->get();
        if ($lines->isEmpty()) {
            return false;
        }

        return $lines->every(
            fn (LpoTxn $t) => $this->returnedQtyForLine($t) + 0.0001 >= (float) $t->ordered_qty,
        );
    }

    public function canReceive(LpoMst $lpo): bool
    {
        if ((int) $lpo->lpo_status_code < LpoStatus::AWAITING_RECEIVE) {
            return false;
        }

        if ((int) $lpo->lpo_status_code === LpoStatus::CANCELLED_RETURNED) {
            return false;
        }

        if ($this->itemsFullyReturnedToSupplier($lpo)) {
            return false;
        }

        return LpoTxn::query()
            ->where('lpo_no', $lpo->lpo_no)
            ->get()
            ->contains(function (LpoTxn $t) {
                $remaining = max(
                    0,
                    (float) $t->ordered_qty
                        - (float) ($t->received_qty ?? 0)
                        - $this->returnedQtyForLine($t),
                );

                return $remaining > 0.0001;
            });
    }

    public function syncReturnStatus(int $lpoNo): void
    {
        $lpo = LpoMst::query()->where('lpo_no', $lpoNo)->first();
        if (! $lpo) {
            return;
        }

        if ((int) $lpo->lpo_status_code < LpoStatus::AWAITING_RECEIVE) {
            return;
        }

        if ($this->itemsFullyReturnedToSupplier($lpo)) {
            if ((int) $lpo->lpo_status_code !== LpoStatus::CANCELLED_RETURNED) {
                $lpo->update(['lpo_status_code' => LpoStatus::CANCELLED_RETURNED]);
            }

            return;
        }

        if ((int) $lpo->lpo_status_code !== LpoStatus::CANCELLED_RETURNED) {
            return;
        }

        $lines = LpoTxn::query()->where('lpo_no', $lpoNo)->get();
        if ($lines->isEmpty()) {
            $lpo->update(['lpo_status_code' => LpoStatus::AWAITING_RECEIVE]);

            return;
        }

        $allReceived = $lines->every(fn ($t) => (float) ($t->received_qty ?? 0) + 0.0001 >= (float) $t->ordered_qty);
        $anyReceived = $lines->contains(fn ($t) => (float) ($t->received_qty ?? 0) > 0);

        if ($allReceived) {
            $code = LpoStatus::FULLY_RECEIVED;
        } elseif ($anyReceived) {
            $code = LpoStatus::AWAITING_LAST_RECEIVE;
        } else {
            $code = LpoStatus::AWAITING_RECEIVE;
        }

        $lpo->update(['lpo_status_code' => $code]);
    }

    public function syncReceiveStatus(int $lpoNo): void
    {
        $lpo = LpoMst::query()->where('lpo_no', $lpoNo)->first();
        if (! $lpo) {
            return;
        }

        if ((int) $lpo->lpo_status_code === LpoStatus::CANCELLED_RETURNED) {
            return;
        }

        if ((int) $lpo->lpo_status_code < LpoStatus::AWAITING_RECEIVE) {
            return;
        }

        $lines = LpoTxn::query()->where('lpo_no', $lpoNo)->get();
        if ($lines->isEmpty()) {
            return;
        }

        $allReceived = $lines->every(fn ($t) => (float) ($t->received_qty ?? 0) + 0.0001 >= (float) $t->ordered_qty);
        $anyReceived = $lines->contains(fn ($t) => (float) ($t->received_qty ?? 0) > 0);

        if ($allReceived) {
            $code = LpoStatus::FULLY_RECEIVED;
        } elseif ($anyReceived) {
            $code = LpoStatus::AWAITING_LAST_RECEIVE;
        } else {
            return;
        }

        if ((int) $lpo->lpo_status_code < $code) {
            $lpo->update(['lpo_status_code' => $code]);
        }
    }

    /**
     * After items are fully received, any supplier payment moves the LPO to Cleared (payment made).
     */
    public function syncClearedStatus(int $lpoNo): void
    {
        $lpo = LpoMst::query()->where('lpo_no', $lpoNo)->first();
        if (! $lpo) {
            return;
        }

        $code = (int) $lpo->lpo_status_code;
        $total = (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0);
        $paid = (float) SupplierPayment::query()->where('lpo_no', $lpoNo)->sum('amount_paid');

        if ($code < LpoStatus::FULLY_RECEIVED) {
            return;
        }

        if ($paid <= 0) {
            if ($code === LpoStatus::CLEARED) {
                $lpo->update([
                    'lpo_status_code' => LpoStatus::FULLY_RECEIVED,
                    'cleared_flag' => 0,
                    'cleared_at' => null,
                    'cleared_by' => null,
                ]);
            }

            return;
        }

        $payable = $this->receivedPayableTotals($lpo)['total'];
        $fullyPaid = $payable <= 0 || $paid + 0.01 >= $payable;

        $lpo->update([
            'lpo_status_code' => LpoStatus::CLEARED,
            'cleared_flag' => $fullyPaid ? 1 : 0,
            'cleared_at' => $fullyPaid ? ($lpo->cleared_at ?? now()) : null,
            'cleared_by' => $fullyPaid ? ($lpo->cleared_by ?? (string) ($lpo->created_by ?? '')) : null,
        ]);
    }

    public function canEdit(LpoMst $lpo): bool
    {
        return (int) $lpo->lpo_status_code < LpoStatus::sentThreshold();
    }

    public function canDelete(LpoMst $lpo): bool
    {
        return $this->canEdit($lpo);
    }

    public function itemsFullyReceived(LpoMst $lpo): bool
    {
        if ((int) $lpo->lpo_status_code >= LpoStatus::FULLY_RECEIVED) {
            return true;
        }

        $lines = LpoTxn::query()->where('lpo_no', $lpo->lpo_no)->get();
        if ($lines->isEmpty()) {
            return false;
        }

        return $lines->every(
            fn ($t) => (float) ($t->received_qty ?? 0) + 0.0001 >= (float) $t->ordered_qty,
        );
    }

    public function hasReceivedItemsForPayment(LpoMst $lpo): bool
    {
        if ((int) $lpo->lpo_status_code < LpoStatus::AWAITING_RECEIVE) {
            return false;
        }

        return LpoTxn::query()
            ->where('lpo_no', $lpo->lpo_no)
            ->get()
            ->contains(fn (LpoTxn $t) => $this->effectiveReceivedQty($t) > 0);
    }

    public function canPay(LpoMst $lpo): bool
    {
        if ((int) $lpo->lpo_status_code === LpoStatus::CANCELLED_RETURNED) {
            return false;
        }

        return $this->hasReceivedItemsForPayment($lpo);
    }

    public function canCreateReturn(LpoMst $lpo): bool
    {
        if ((int) $lpo->lpo_status_code < LpoStatus::AWAITING_RECEIVE) {
            return false;
        }

        if ((int) $lpo->lpo_status_code === LpoStatus::CANCELLED_RETURNED) {
            return false;
        }

        return LpoTxn::query()
            ->where('lpo_no', $lpo->lpo_no)
            ->get()
            ->contains(fn (LpoTxn $t) => $this->maxReturnQty($t) > 0);
    }

    public function assertCanPay(LpoMst $lpo): void
    {
        if (! $this->canPay($lpo)) {
            throw new InvalidArgumentException(
                'Supplier payment is only allowed after at least some items on this LPO have been received.',
            );
        }
    }

    public function returnedQtyByProduct(int $lpoNo): array
    {
        return $this->sumReturnQtyByProduct($lpoNo, approvedOnly: true);
    }

    public function committedReturnQtyByProduct(int $lpoNo): array
    {
        return $this->sumReturnQtyByProduct($lpoNo, approvedOnly: false);
    }

    /**
     * @return array<string, float>
     */
    protected function sumReturnQtyByProduct(int $lpoNo, bool $approvedOnly): array
    {
        $query = SupplierReturn::query()
            ->where('reference_type', 'lpo')
            ->where('reference_id', $lpoNo);
        $this->applyReturnDocumentStatusFilter($query, $approvedOnly);

        return $query
            ->selectRaw('product_code, SUM(quantity) as qty')
            ->groupBy('product_code')
            ->pluck('qty', 'product_code')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    /** Approved returns only (affects stock and payable). */
    public function returnedQtyApprovedForLine(LpoTxn $txn): float
    {
        return $this->sumReturnQtyForLine($txn, approvedOnly: true);
    }

    /** Approved + pending (caps new return qty on LPO). */
    public function returnedQtyForLine(LpoTxn $txn): float
    {
        return $this->sumReturnQtyForLine($txn, approvedOnly: false);
    }

    protected function sumReturnQtyForLine(LpoTxn $txn, bool $approvedOnly): float
    {
        $query = SupplierReturn::query()
            ->where('reference_type', 'lpo')
            ->where('reference_id', $txn->lpo_no)
            ->where('product_code', $txn->product_code);
        $this->applyReturnDocumentStatusFilter($query, $approvedOnly);

        return (float) $query->sum('quantity');
    }

    protected function applyReturnDocumentStatusFilter($query, bool $approvedOnly): void
    {
        $statuses = $approvedOnly ? ['approved'] : ['approved', 'pending_approval'];

        $query->where(function ($q) use ($statuses) {
            $q->whereNull('document_id')
                ->orWhereIn('document_id', function ($sub) use ($statuses) {
                    $sub->select('id')
                        ->from('supplier_return_documents')
                        ->whereIn('status', $statuses);
                });
        });
    }

    public function effectiveReceivedQty(LpoTxn $txn): float
    {
        return max(0, (float) ($txn->received_qty ?? 0) - $this->returnedQtyApprovedForLine($txn));
    }

    public function returnableQty(LpoTxn $txn): float
    {
        return max(0, (float) ($txn->received_qty ?? 0) - $this->returnedQtyApprovedForLine($txn));
    }

    /** Max qty returnable on the LPO line (ordered minus prior returns). */
    public function maxReturnQty(LpoTxn $txn): float
    {
        return max(0, (float) $txn->ordered_qty - $this->returnedQtyForLine($txn));
    }

    /** Qty to remove from branch stock for this return (only for received portion). */
    public function stockDeductQtyForReturn(LpoTxn $txn, float $returnQty): float
    {
        $received = (float) ($txn->received_qty ?? 0);
        $priorReturned = $this->returnedQtyApprovedForLine($txn);

        return min($returnQty, max(0, $received - $priorReturned));
    }

    /**
     * Base units received per shop/store for an LPO line (from linked stock receipts).
     *
     * @return array{shop: float, store: float}
     */
    public function receivedBaseQtyByLocation(int $lpoNo, int $lpoTxnId): array
    {
        if (! Schema::hasColumn('stock_receipts', 'lpo_no')
            || ! Schema::hasColumn('stock_receipts', 'lpo_txn_id')) {
            return ['shop' => 0, 'store' => 0];
        }

        $rows = DB::table('stock_receipts')
            ->where('lpo_no', $lpoNo)
            ->where('lpo_txn_id', $lpoTxnId)
            ->selectRaw('stock_location, SUM(units_received) as total')
            ->groupBy('stock_location')
            ->get();

        return [
            'shop' => (float) ($rows->firstWhere('stock_location', 'shop')?->total ?? 0),
            'store' => (float) ($rows->firstWhere('stock_location', 'store')?->total ?? 0),
        ];
    }

    /**
     * @param  array{shop: float, store: float}  $byLocation
     * @return list<string>
     */
    public function allowedReturnLocations(array $byLocation): array
    {
        $allowed = [];
        if (($byLocation['shop'] ?? 0) > 0) {
            $allowed[] = 'shop';
        }
        if (($byLocation['store'] ?? 0) > 0) {
            $allowed[] = 'store';
        }

        return $allowed;
    }

    /**
     * @param  array{shop: float, store: float}  $byLocation
     */
    public function primaryReceivedLocation(array $byLocation): ?string
    {
        $allowed = $this->allowedReturnLocations($byLocation);
        if ($allowed === []) {
            return null;
        }
        if (count($allowed) === 1) {
            return $allowed[0];
        }

        return ($byLocation['store'] ?? 0) >= ($byLocation['shop'] ?? 0) ? 'store' : 'shop';
    }

    public function resolveLpoReturnStockLocation(LpoTxn $txn, ?string $requested): string
    {
        $byLocation = $this->receivedBaseQtyByLocation((int) $txn->lpo_no, (int) $txn->id);
        $allowed = $this->allowedReturnLocations($byLocation);

        if ($allowed === []) {
            if (! in_array($requested, ['shop', 'store'], true)) {
                throw new InvalidArgumentException('Select Shop or Store for the return location.');
            }

            return $requested;
        }

        if (count($allowed) === 1) {
            return $allowed[0];
        }

        if (! in_array($requested, ['shop', 'store'], true)) {
            throw new InvalidArgumentException('Select Shop or Store for the return location.');
        }
        if (! in_array($requested, $allowed, true)) {
            throw new InvalidArgumentException(
                'Return stock from the same location it was received into on this LPO ('
                .implode(' or ', array_map(fn ($l) => ucfirst($l), $allowed)).').',
            );
        }

        return $requested;
    }

    /**
     * @return array{subtotal: float, vat: float, total: float}
     */
    /** LPO order total after supplier returns (committed), excluding invoice overrides. */
    public function orderNetAfterReturns(LpoMst $lpo): float
    {
        $original = (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0);
        $lines = LpoTxn::query()->where('lpo_no', $lpo->lpo_no)->get();
        if ($lines->isEmpty()) {
            return round($original, 2);
        }

        $committedByProduct = $this->committedReturnQtyByProduct((int) $lpo->lpo_no);
        $products = Product::query()
            ->whereIn('product_code', $lines->pluck('product_code'))
            ->get()
            ->keyBy('product_code');
        $vatPcts = DB::table('vats')->pluck('vat_percentage', 'id');

        $credit = 0.0;
        foreach ($lines as $line) {
            $committed = (float) ($committedByProduct[$line->product_code] ?? 0);
            if ($committed <= 0) {
                continue;
            }
            $cost = (float) ($line->cost_price ?? 0);
            $lineNet = $committed * $cost;
            $product = $products[$line->product_code] ?? null;
            $vatPct = $product ? (float) ($vatPcts[$product->vat_id] ?? 0) : 0;
            $credit += $lineNet * (1 + $vatPct / 100);
        }

        return round(max(0, $original - $credit), 2);
    }

    public function receivedPayableTotals(LpoMst $lpo): array
    {
        $lines = LpoTxn::query()->where('lpo_no', $lpo->lpo_no)->get();
        $products = Product::query()
            ->whereIn('product_code', $lines->pluck('product_code'))
            ->get()
            ->keyBy('product_code');
        $vatPcts = DB::table('vats')->pluck('vat_percentage', 'id');

        $subtotal = 0.0;
        $vat = 0.0;
        foreach ($lines as $line) {
            $effective = $this->effectiveReceivedQty($line);
            if ($effective <= 0) {
                continue;
            }
            $cost = (float) ($line->cost_price ?? 0);
            $lineNet = $effective * $cost;
            $subtotal += $lineNet;
            $product = $products[$line->product_code] ?? null;
            $vatPct = $product ? (float) ($vatPcts[$product->vat_id] ?? 0) : 0;
            $vat += $lineNet * ($vatPct / 100);
        }

        $subtotal = round($subtotal, 2);
        $vat = round($vat, 2);

        return [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => round($subtotal + $vat, 2),
        ];
    }

    public function payableBalanceDue(LpoMst $lpo, ?float $paid = null): float
    {
        $paid ??= (float) SupplierPayment::query()
            ->where('lpo_no', $lpo->lpo_no)
            ->sum('amount_paid');
        $receivedTotal = $this->receivedPayableTotals($lpo)['total'];

        return round(max(0, $receivedTotal - $paid), 2);
    }

    public function listSupplierReturns(int $lpoNo): array
    {
        return app(SupplierReturnDocumentService::class)->listForLpo($lpoNo);
    }

    public function delete(int $lpoNo, int $userId): void
    {
        $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->firstOrFail();
        if (! $this->canDelete($lpo)) {
            throw new InvalidArgumentException(
                'This LPO cannot be deleted after it has been sent to the supplier.',
            );
        }

        $supplierId = (int) $lpo->supplier_id;
        $lpo->update([
            'deleted_at' => now(),
            'deleted_by' => (string) $userId,
        ]);
        $this->supplierBalances->recalculate($supplierId);
    }

    /**
     * @return array<int, string>
     */
    public function workflowActions(LpoMst $lpo, float $balanceDue): array
    {
        $code = (int) $lpo->lpo_status_code;
        $actions = [];

        if ($code === LpoStatus::CANCELLED_RETURNED) {
            return $actions;
        }

        if ($code === LpoStatus::AWAITING_CHECK) {
            $actions[] = 'mark_checked';
        }
        if ($code === LpoStatus::AWAITING_APPROVAL) {
            $actions[] = 'approve';
        }
        if ($code === LpoStatus::AWAITING_SEND) {
            $actions[] = 'send_email';
            $actions[] = 'send_whatsapp';
            $actions[] = 'mark_sent';
        }
        return $actions;
    }

    public function transition(int $lpoNo, string $action, int $userId): LpoMst
    {
        $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->firstOrFail();
        $code = (int) $lpo->lpo_status_code;

        return match ($action) {
            'mark_checked' => $this->applyTransition($lpo, $code, LpoStatus::AWAITING_CHECK, LpoStatus::AWAITING_APPROVAL),
            'approve' => $this->applyTransition($lpo, $code, LpoStatus::AWAITING_APPROVAL, LpoStatus::AWAITING_SEND),
            'mark_sent' => $this->markSentToSupplier($lpo, $code, $userId),
            default => throw new InvalidArgumentException('Unknown workflow action.'),
        };
    }

    protected function applyTransition(LpoMst $lpo, int $current, int $expected, int $next): LpoMst
    {
        if ($current !== $expected) {
            throw new RuntimeException('This action is not available for the current LPO status.');
        }

        $lpo->update(['lpo_status_code' => $next]);

        return $lpo->fresh();
    }

    public function markSentToSupplier(LpoMst $lpo, ?int $currentCode, int $userId, ?string $channel = null): LpoMst
    {
        $code = $currentCode ?? (int) $lpo->lpo_status_code;
        if ($code !== LpoStatus::AWAITING_SEND) {
            throw new RuntimeException('LPO must be awaiting send before marking as sent.');
        }

        $lpo->update([
            'lpo_status_code' => LpoStatus::AWAITING_RECEIVE,
            'email_sent_flag' => 1,
            'sent_at' => now(),
            'sent_by' => (string) $userId,
        ]);

        return $lpo->fresh();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>|array<int, string>  $statusNames
     */
    protected function lpoOrderDate(LpoMst $l): ?string
    {
        if ($l->created_at) {
            return Carbon::parse($l->created_at)->format('Y-m-d');
        }
        if ($l->sent_at) {
            return Carbon::parse($l->sent_at)->format('Y-m-d');
        }

        return null;
    }

    protected function mapListRow(LpoMst $l, ?Supplier $supplier, $statusNames, $paidByLpo, $creatorsById = null): array
    {
        $total = (float) ($l->net_amount ?? $l->total_amount ?? 0);
        $paid = (float) ($paidByLpo[$l->lpo_no] ?? 0);
        $receivedPayable = $this->receivedPayableTotals($l);
        $payableTotal = $receivedPayable['total'];
        $balanceDue = $this->payableBalanceDue($l, $paid);
        $subtotal = $total - (float) ($l->vat_amount ?? 0);
        $paymentStatus = $this->payablePaymentStatus($l, $paid, $payableTotal);
        $status = $this->resolveStatusDisplay(
            (int) $l->lpo_status_code,
            $statusNames,
            $payableTotal,
            $paid,
        );
        $status['payment_status'] = $paymentStatus;

        $creator = $creatorsById && $l->created_by ? ($creatorsById[$l->created_by] ?? null) : null;

        return [
            'lpo_no' => (int) $l->lpo_no,
            'po_number' => $this->formatPoNumber((int) $l->lpo_no),
            'supplier_id' => (int) $l->supplier_id,
            'supplier_name' => $supplier?->supplier_name,
            'reference_number' => $l->reference_number,
            'supplier_invoice_no' => $l->supplier_invoice_no,
            'created_by' => $l->created_by ? (int) $l->created_by : null,
            'created_by_name' => $creator?->full_name ?: $creator?->username,
            'order_date' => $this->lpoOrderDate($l),
            'due_date' => $l->due_date ? Carbon::parse($l->due_date)->format('Y-m-d') : null,
            'delivery_address' => $l->delivery_address,
            'lpo_status_code' => (int) $l->lpo_status_code,
            'status_name' => $status['status_name'],
            'payment_status' => $status['payment_status'],
            'subtotal' => round(max(0, $subtotal), 2),
            'vat_amount' => (float) ($l->vat_amount ?? 0),
            'total_amount' => (float) ($l->total_amount ?? 0),
            'net_amount' => $total,
            'amount_paid' => round($paid, 2),
            'balance_due' => $balanceDue,
            'received_payable_total' => $payableTotal,
            'can_pay' => $this->canPay($l),
            'cleared_flag' => (int) ($l->cleared_flag ?? 0),
            'email_sent_flag' => (int) ($l->email_sent_flag ?? 0),
            'sent_at' => $l->sent_at,
            'terms' => $l->terms,
            'instructions' => $l->instructions,
        ];
    }

    public function payablePaymentStatus(LpoMst $lpo, ?float $paid = null, ?float $payableTotal = null): string
    {
        $paid ??= (float) SupplierPayment::query()
            ->where('lpo_no', $lpo->lpo_no)
            ->sum('amount_paid');
        $payableTotal ??= $this->receivedPayableTotals($lpo)['total'];

        if ($payableTotal <= 0) {
            return $paid > 0 ? 'paid' : 'unpaid';
        }

        if ($paid + 0.01 >= $payableTotal) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partial';
        }

        return 'unpaid';
    }

    protected function paymentStatus(float $payableTotal, float $paid): string
    {
        if ($payableTotal <= 0) {
            return $paid > 0 ? 'paid' : 'unpaid';
        }
        if ($paid + 0.01 >= $payableTotal) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }

        return 'unpaid';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>|array<int, string>  $statusNames
     * @return array{status_name: string, payment_status: string}
     */
    protected function resolveStatusDisplay(int $code, $statusNames, float $payableTotal, float $paid): array
    {
        $base = $statusNames[$code] ?? 'Status ' . $code;
        $paymentStatus = $this->paymentStatus($payableTotal, $paid);

        if ($code === LpoStatus::CLEARED && $paid > 0) {
            $paidLabel = 'KES ' . number_format($paid, 2);
            if ($paymentStatus === 'paid') {
                return [
                    'status_name' => "LPO Cleared (Payment Made – Fully paid, {$paidLabel})",
                    'payment_status' => 'paid',
                ];
            }

            $payableLabel = 'KES ' . number_format($payableTotal, 2);

            return [
                'status_name' => "LPO Cleared (Payment Made – Partially paid, {$paidLabel} of {$payableLabel})",
                'payment_status' => 'partial',
            ];
        }

        if ($code === LpoStatus::FULLY_RECEIVED && $paid > 0) {
            return $this->resolveStatusDisplay(LpoStatus::CLEARED, $statusNames, $payableTotal, $paid);
        }

        return [
            'status_name' => $base,
            'payment_status' => $paymentStatus,
        ];
    }

    /**
     * Remember supplier invoice numbers entered during stock receipt so returns can reference them.
     */
    public function recordSupplierInvoiceFromReceive(int $lpoNo, string $invoiceNumber): void
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            return;
        }

        $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->first();
        if (! $lpo) {
            return;
        }

        LpoSupplierInvoice::query()->firstOrCreate(
            [
                'lpo_no' => $lpoNo,
                'supplier_invoice_number' => $invoiceNumber,
            ],
            [
                'supplier_id' => (int) $lpo->supplier_id,
                'invoice_date' => now()->toDateString(),
            ],
        );

        if (! $lpo->supplier_invoice_no) {
            $lpo->update(['supplier_invoice_no' => $invoiceNumber]);
        }
    }
}
