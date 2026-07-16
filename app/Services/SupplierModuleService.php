<?php

namespace App\Services;

use App\Models\LpoAttachment;
use App\Models\LpoMst;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Auth\UserAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierModuleService
{
    public function __construct(
        protected LpoModuleService $lpoModule,
    ) {}

    public function dashboard(int $organizationId): array
    {
        $base = Supplier::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at');

        $supplierIds = (clone $base)->pluck('id');
        $amountOwing = $this->balancesForSuppliers($supplierIds, $organizationId)->sum();

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('is_active', true)->count(),
            'amount_owing' => round($amountOwing, 2),
            'credit_due' => round($amountOwing, 2),
        ];
    }

    /**
     * @param  Collection<int, int|string>|array<int, int|string>  $supplierIds
     * @return Collection<int, float>
     */
    public function balancesForSuppliers(Collection|array $supplierIds, int $organizationId): Collection
    {
        $ids = collect($supplierIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $receivedBySupplier = DB::table('lpo_mst as m')
            ->join('lpo_txn as t', 't.lpo_no', '=', 'm.lpo_no')
            ->join('suppliers as s', 's.id', '=', 'm.supplier_id')
            ->where('s.organization_id', $organizationId)
            ->whereNull('m.deleted_at')
            ->whereIn('m.supplier_id', $ids)
            ->groupBy('m.supplier_id')
            ->selectRaw('m.supplier_id, SUM(t.received_qty * t.cost_price) as received_payable')
            ->pluck('received_payable', 'supplier_id');

        $paidBySupplier = SupplierPayment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('supplier_id', $ids)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, SUM(amount_paid) as total_paid')
            ->pluck('total_paid', 'supplier_id');

        return $ids->mapWithKeys(function (int $supplierId) use ($receivedBySupplier, $paidBySupplier) {
            $received = (float) ($receivedBySupplier[$supplierId] ?? 0);
            $paid = (float) ($paidBySupplier[$supplierId] ?? 0);

            return [$supplierId => round(max(0, $received - $paid), 2)];
        });
    }

    public function summary(Supplier $supplier): array
    {
        $lpos = LpoMst::query()
            ->where('supplier_id', $supplier->id)
            ->whereNull('deleted_at')
            ->orderByDesc('lpo_no')
            ->get();

        $purchases = [];
        $documents = [];
        $totalPurchases = 0.0;
        $totalPaid = 0.0;
        $openLpoCount = 0;
        $invoiceCount = 0;
        $lastDelivery = null;
        $leadTimes = [];

        foreach ($lpos as $lpo) {
            $lpoSummary = $this->lpoModule->summary((int) $lpo->lpo_no);
            $purchase = $this->mapPurchaseRow($lpoSummary);
            $purchases[] = $purchase;

            $totalPurchases += (float) ($purchase['net_amount'] ?? $purchase['total_amount'] ?? 0);
            $totalPaid += (float) ($purchase['amount_paid'] ?? 0);

            if (! in_array($purchase['payment_status'] ?? '', ['paid'], true)
                && (float) ($purchase['balance_due'] ?? 0) > 0) {
                $openLpoCount++;
            }

            $invoiceCount += count($lpoSummary['supplier_invoices'] ?? []);

            if ((float) ($purchase['received_payable_total'] ?? 0) > 0) {
                $receivedAt = $this->asCarbon($lpo->cleared_at ?? $lpo->sent_at ?? $lpo->created_at);
                if ($receivedAt && (! $lastDelivery || $receivedAt->gt($lastDelivery))) {
                    $lastDelivery = $receivedAt;
                }
                $createdAt = $this->asCarbon($lpo->created_at);
                if ($createdAt && $receivedAt) {
                    $leadTimes[] = $createdAt->diffInDays($receivedAt);
                }
            }

            foreach ($lpoSummary['supplier_invoices'] as $inv) {
                $documents[] = [
                    'id' => 'inv-' . $inv['id'],
                    'lpo_no' => (int) $lpo->lpo_no,
                    'lpo_seq' => $purchase['lpo_seq'] ?? null,
                    'po_number' => $purchase['po_number'] ?? null,
                    'reference_number' => $purchase['reference_number'] ?? null,
                    'order_date' => $purchase['order_date'],
                    'file_name' => $inv['supplier_invoice_number'] ?? $inv['number'] ?? 'Supplier invoice',
                    'status_name' => $purchase['status_name'],
                    'supplier_invoice_no' => $inv['supplier_invoice_number'] ?? $inv['number'],
                    'total_amount' => (float) ($purchase['net_amount'] ?? $purchase['total_amount'] ?? 0),
                    'balance_due' => (float) ($purchase['balance_due'] ?? 0),
                ];
            }
        }

        $attachments = LpoAttachment::query()
            ->whereIn('lpo_no', $lpos->pluck('lpo_no'))
            ->get();

        $purchaseByLpo = collect($purchases)->keyBy('lpo_no');
        foreach ($attachments as $attachment) {
            $purchase = $purchaseByLpo->get((int) $attachment->lpo_no, []);
            $documents[] = [
                'id' => 'att-' . $attachment->id,
                'lpo_no' => (int) $attachment->lpo_no,
                'lpo_seq' => $purchase['lpo_seq'] ?? null,
                'po_number' => $purchase['po_number'] ?? null,
                'reference_number' => $purchase['reference_number'] ?? null,
                'order_date' => $purchase['order_date'] ?? null,
                'file_name' => $attachment->file_name,
                'status_name' => $purchase['status_name'] ?? null,
                'supplier_invoice_no' => $purchase['supplier_invoice_no'] ?? null,
                'total_amount' => (float) ($purchase['net_amount'] ?? $purchase['total_amount'] ?? 0),
                'balance_due' => (float) ($purchase['balance_due'] ?? 0),
            ];
        }

        $currentBalance = $this->balancesForSuppliers(collect([$supplier->id]), (int) $supplier->organization_id)
            ->get($supplier->id, 0.0);

        $supplierPayload = $supplier->toArray();
        $supplierPayload['current_balance'] = $currentBalance;

        return [
            'supplier' => $supplierPayload,
            'stats' => [
                'total_purchases' => round($totalPurchases, 2),
                'total_paid' => round($totalPaid, 2),
                'open_lpo_count' => $openLpoCount,
                'invoice_count' => $invoiceCount,
                'last_delivery' => $lastDelivery?->toDateString(),
                'average_lead_time_days' => count($leadTimes) > 0
                    ? (int) round(array_sum($leadTimes) / count($leadTimes))
                    : null,
            ],
            'purchases' => $purchases,
            'payments' => $this->paymentsForSupplier($supplier->id),
            'documents' => $documents,
        ];
    }

    public function paymentsForSupplier(int $supplierId): array
    {
        return SupplierPayment::query()
            ->with(['paymentMethod', 'paidByUser'])
            ->where('supplier_id', $supplierId)
            ->orderByDesc('date_paid')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupplierPayment $payment) => $this->mapPaymentRow($payment))
            ->values()
            ->all();
    }

    public function listPayments(Request $request, int $organizationId): array
    {
        $query = SupplierPayment::query()
            ->with(['supplier', 'paymentMethod', 'paidByUser'])
            ->where('organization_id', $organizationId)
            ->orderByDesc('date_paid')
            ->orderByDesc('id');

        $user = $request->user();
        if ($user) {
            app(UserAccessService::class)->applyBranchListFilter($query, $user, $request);
        }

        if ($supplierId = $request->input('supplier_id') ?? $request->input('filter.supplier_id')) {
            $query->where('supplier_id', (int) $supplierId);
        }
        if ($from = $request->input('date_from') ?? $request->input('filter.date_from')) {
            $query->whereDate('date_paid', '>=', $from);
        }
        if ($to = $request->input('date_to') ?? $request->input('filter.date_to')) {
            $query->whereDate('date_paid', '<=', $to);
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $rows = $query->limit($perPage)->get();

        return $rows->map(fn (SupplierPayment $payment) => $this->mapPaymentRow($payment))->values()->all();
    }

    public function recordPayment(Request $request, Supplier $supplier): SupplierPayment
    {
        $data = $request->validate([
            'lpo_no' => 'nullable|integer',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'amount_paid' => 'required|numeric|min:0.01',
            'manual_amount' => 'nullable|boolean',
            'declared_payable' => 'nullable|numeric|min:0',
            'amount_due_snapshot' => 'nullable|numeric|min:0',
            'cheque_number' => 'nullable|string|max:45',
            'reference_number' => 'nullable|string|max:100',
            'date_paid' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $access = app(UserAccessService::class);
        $manual = (bool) ($data['manual_amount'] ?? false);
        $amount = (float) $data['amount_paid'];
        $lpoNo = isset($data['lpo_no']) ? (int) $data['lpo_no'] : null;
        $lpo = null;

        if ($lpoNo) {
            $lpo = LpoMst::query()
                ->where('lpo_no', $lpoNo)
                ->where('supplier_id', $supplier->id)
                ->whereNull('deleted_at')
                ->first();

            if (! $lpo) {
                throw ValidationException::withMessages([
                    'lpo_no' => ['The selected LPO does not belong to this supplier.'],
                ]);
            }

            if (! $manual) {
                $lpoSummary = $this->lpoModule->summary($lpoNo);
                $balanceDue = (float) ($lpoSummary['balance_due'] ?? 0);
                if ($balanceDue <= 0) {
                    throw ValidationException::withMessages([
                        'amount_paid' => ['This LPO has no balance on record. Use manual payable amount if needed.'],
                    ]);
                }
                if ($amount > $balanceDue + 0.01) {
                    throw ValidationException::withMessages([
                        'amount_paid' => ["Amount exceeds balance due ({$balanceDue})."],
                    ]);
                }
            }
        } elseif (! $manual) {
            $balance = $this->balancesForSuppliers(collect([$supplier->id]), (int) $supplier->organization_id)
                ->get($supplier->id, 0.0);
            if ($amount > $balance + 0.01) {
                throw ValidationException::withMessages([
                    'amount_paid' => ["Amount exceeds supplier balance due ({$balance})."],
                ]);
            }
        }

        $payableBase = (float) ($data['amount_due_snapshot'] ?? 0);
        if ($manual && $payableBase > 0 && $amount > $payableBase + 0.01) {
            throw ValidationException::withMessages([
                'amount_paid' => ["Payment exceeds payable amount ({$payableBase})."],
            ]);
        }

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if (! $branchId && $lpo?->branch_id) {
            $branchId = (int) $lpo->branch_id;
        }
        if (! $branchId && $user?->branch_id) {
            $branchId = (int) $user->branch_id;
        }
        if ($branchId && $user) {
            $access->assertBranchInOrganization($user, $branchId, $request);
            $access->assertBranchAccess($user, $branchId);
        }

        return DB::transaction(function () use ($request, $supplier, $data, $manual, $amount, $lpoNo, $branchId) {
            $payment = SupplierPayment::create([
                'organization_id' => $supplier->organization_id,
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'lpo_no' => $lpoNo,
                'payment_method_id' => (int) $data['payment_method_id'],
                'amount_paid' => $amount,
                'manual_amount' => $manual,
                'declared_payable' => $manual ? ($data['declared_payable'] ?? null) : null,
                'amount_due_snapshot' => $data['amount_due_snapshot'] ?? null,
                'cheque_number' => $data['cheque_number'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'date_paid' => $data['date_paid'],
                'paid_by' => (int) $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($lpoNo) {
                $this->syncLpoClearedStatus($lpoNo);
            }

            return $payment;
        });
    }

    protected function syncLpoClearedStatus(int $lpoNo): void
    {
        $summary = $this->lpoModule->summary($lpoNo);
        $balanceDue = (float) ($summary['balance_due'] ?? 0);

        if ($balanceDue > 0.01) {
            return;
        }

        LpoMst::query()
            ->where('lpo_no', $lpoNo)
            ->update([
                'cleared_flag' => 1,
                'cleared_at' => now(),
                'lpo_status_code' => LpoModuleService::STATUS_CLEARED,
            ]);
    }

    protected function mapPurchaseRow(array $lpoSummary): array
    {
        $lpo = $lpoSummary['lpo'];
        $receivedPayable = (float) ($lpo['received_payable_total'] ?? 0);

        return [
            'lpo_no' => (int) $lpo['lpo_no'],
            'lpo_seq' => (int) ($lpo['lpo_seq'] ?? 0),
            'po_number' => $lpo['po_number'] ?? null,
            'status_name' => $lpo['status_name'] ?? null,
            'supplier_invoice_no' => $lpo['supplier_invoice_no'] ?? null,
            'reference_number' => $lpo['reference_number'] ?? null,
            'order_date' => $lpo['order_date'] ?? $lpo['created_at'] ?? null,
            'net_amount' => (float) ($lpo['net_amount'] ?? 0),
            'total_amount' => (float) ($lpo['total_amount'] ?? 0),
            'amount_paid' => (float) ($lpo['amount_paid'] ?? 0),
            'balance_due' => (float) ($lpo['balance_due'] ?? 0),
            'payment_status' => $lpo['payment_status'] ?? 'unpaid',
            'received_payable_total' => $receivedPayable,
            'items_fully_received' => $receivedPayable > 0,
            'can_pay' => $receivedPayable > 0,
        ];
    }

    public function formatPayment(SupplierPayment $payment): array
    {
        return $this->mapPaymentRow($payment);
    }

    protected function asCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    protected function mapPaymentRow(SupplierPayment $payment): array
    {
        $dueSnapshot = (float) ($payment->amount_due_snapshot ?? 0);
        $amountPaid = (float) $payment->amount_paid;
        $lpoDisplay = $this->lpoDisplayFields($payment->lpo_no ? (int) $payment->lpo_no : null);

        return [
            'id' => (int) $payment->id,
            'organization_id' => (int) $payment->organization_id,
            'branch_id' => $payment->branch_id ? (int) $payment->branch_id : null,
            'supplier_id' => (int) $payment->supplier_id,
            'supplier_name' => $payment->supplier?->supplier_name,
            'lpo_no' => $payment->lpo_no ? (int) $payment->lpo_no : null,
            'lpo_seq' => $lpoDisplay['lpo_seq'],
            'po_number' => $lpoDisplay['po_number'],
            'amount_paid' => $amountPaid,
            'amount_due_snapshot' => $dueSnapshot > 0 ? $dueSnapshot : null,
            'is_partial' => $dueSnapshot > 0 && $amountPaid + 0.01 < $dueSnapshot,
            'manual_amount' => (bool) $payment->manual_amount,
            'payment_method' => $payment->paymentMethod?->method_name,
            'payment_method_id' => (int) $payment->payment_method_id,
            'reference_number' => $payment->reference_number,
            'cheque_number' => $payment->cheque_number,
            'date_paid' => $payment->date_paid?->toDateString(),
            'paid_by' => (int) $payment->paid_by,
            'paid_by_name' => $payment->paidByUser?->full_name ?? $payment->paidByUser?->username,
            'notes' => $payment->notes,
        ];
    }

    /** @return array{lpo_seq: ?int, po_number: ?string} */
    protected function lpoDisplayFields(?int $lpoNo): array
    {
        if (! $lpoNo) {
            return ['lpo_seq' => null, 'po_number' => null];
        }

        static $cache = [];
        if (! array_key_exists($lpoNo, $cache)) {
            $lpo = LpoMst::query()
                ->select('lpo_no', 'lpo_seq', 'created_at', 'sent_at', 'reference_number')
                ->where('lpo_no', $lpoNo)
                ->first();

            if (! $lpo) {
                $cache[$lpoNo] = ['lpo_seq' => null, 'po_number' => null];
            } else {
                $orderDate = $lpo->created_at ?? $lpo->sent_at;
                $cache[$lpoNo] = [
                    'lpo_seq' => (int) $lpo->lpo_seq,
                    'po_number' => $this->lpoModule->formatPoNumber((int) $lpo->lpo_seq, $orderDate),
                ];
            }
        }

        return $cache[$lpoNo];
    }
}
