<?php

namespace App\Services;

use App\Models\LpoAttachment;
use App\Models\LpoMst;
use App\Models\LpoSupplierInvoice;
use App\Support\LpoStatus;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SupplierSummaryService
{
    public function __construct(
        protected LpoModuleService $lpoModule,
    ) {}

    public function build(int $supplierId): array
    {
        $supplier = Supplier::query()->whereNull('deleted_at')->findOrFail($supplierId);

        $lpos = LpoMst::query()
            ->where('supplier_id', $supplier->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $lpoNos = $lpos->pluck('lpo_no')->filter()->values();
        $statusNames = DB::table('lpo_statuses')->pluck('status_name', 'status_code');

        $lineStats = $lpoNos->isEmpty()
            ? collect()
            : collect(
                DB::table('lpo_txn')
                    ->whereIn('lpo_no', $lpoNos)
                    ->selectRaw('lpo_no, COUNT(*) as line_count, SUM(ordered_qty) as ordered_qty, SUM(COALESCE(received_qty, 0)) as received_qty')
                    ->groupBy('lpo_no')
                    ->get(),
            )->keyBy('lpo_no');

        $paidByLpo = $lpoNos->isEmpty()
            ? collect()
            : SupplierPayment::query()
                ->whereIn('lpo_no', $lpoNos)
                ->selectRaw('lpo_no, SUM(amount_paid) as amount_paid')
                ->groupBy('lpo_no')
                ->pluck('amount_paid', 'lpo_no');

        $invoices = LpoSupplierInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lpo_no');

        $purchases = $lpos->map(function ($l) use ($statusNames, $lineStats, $paidByLpo, $invoices) {
            $total = (float) ($l->net_amount ?? $l->total_amount ?? 0);
            $paid = (float) ($paidByLpo[$l->lpo_no] ?? 0);
            $lines = $lineStats[$l->lpo_no] ?? null;
            $lpoInvoices = $invoices[$l->lpo_no] ?? collect();

            $ordered = (float) ($lines->ordered_qty ?? 0);
            $received = (float) ($lines->received_qty ?? 0);
            $itemsFullyReceived = (int) $l->lpo_status_code >= LpoStatus::FULLY_RECEIVED
                || ($ordered > 0 && $received + 0.0001 >= $ordered);
            $receivedPayable = $this->lpoModule->receivedPayableTotals($l)['total'];
            $balanceDue = $this->lpoModule->payableBalanceDue($l, $paid);

            return [
                'lpo_no' => (int) $l->lpo_no,
                'reference_number' => $l->reference_number,
                'supplier_invoice_no' => $l->supplier_invoice_no,
                'status_code' => (int) $l->lpo_status_code,
                'status_name' => $statusNames[$l->lpo_status_code] ?? 'Status ' . $l->lpo_status_code,
                'items_fully_received' => $itemsFullyReceived,
                'can_pay' => $this->lpoModule->canPay($l),
                'received_payable_total' => $receivedPayable,
                'total_amount' => (float) ($l->total_amount ?? 0),
                'net_amount' => (float) ($l->net_amount ?? 0),
                'amount_paid' => round($paid, 2),
                'balance_due' => $balanceDue,
                'payment_status' => $this->paymentStatus($receivedPayable, $paid),
                'order_date' => $l->created_at ? Carbon::parse($l->created_at)->format('Y-m-d') : null,
                'due_date' => $l->due_date ? Carbon::parse($l->due_date)->format('Y-m-d') : null,
                'cleared_flag' => (int) ($l->cleared_flag ?? 0),
                'line_count' => (int) ($lines->line_count ?? 0),
                'ordered_qty' => (float) ($lines->ordered_qty ?? 0),
                'received_qty' => (float) ($lines->received_qty ?? 0),
                'supplier_invoices' => $lpoInvoices->map(fn ($inv) => [
                    'id' => $inv->id,
                    'supplier_invoice_number' => $inv->supplier_invoice_number,
                    'invoice_date' => $inv->invoice_date
                        ? Carbon::parse($inv->invoice_date)->format('Y-m-d')
                        : null,
                    'invoice_amount' => (float) ($inv->invoice_amount ?? 0),
                    'file_name' => $inv->file_name,
                    'has_document' => (bool) $inv->file_path,
                ])->values(),
            ];
        })->values();

        $paymentMethods = PaymentMethod::query()->pluck('method_name', 'id');
        $payerIds = SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->pluck('paid_by')
            ->unique()
            ->filter();
        $payers = User::query()->whereIn('id', $payerIds)->get()->keyBy('id');

        $payments = SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('date_paid')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function ($p) use ($paymentMethods, $payers) {
                $snapshot = (float) ($p->amount_due_snapshot ?? 0);
                $paid = (float) $p->amount_paid;
                $wasPartial = $snapshot > 0 && $paid + 0.01 < $snapshot;

                return [
                    'id' => $p->id,
                    'date_paid' => $p->date_paid?->format('Y-m-d'),
                    'amount_paid' => $paid,
                    'amount_due_snapshot' => $snapshot > 0 ? $snapshot : null,
                    'is_partial' => $wasPartial,
                    'lpo_no' => $p->lpo_no,
                    'payment_method' => $paymentMethods[$p->payment_method_id] ?? '—',
                    'reference_number' => $p->reference_number,
                    'cheque_number' => $p->cheque_number,
                    'notes' => $p->notes,
                    'paid_by_name' => $this->userLabel($payers[$p->paid_by] ?? null),
                ];
            });

        $lpoByNo = $lpos->keyBy('lpo_no');
        $documents = $lpoNos->isEmpty()
            ? collect()
            : LpoAttachment::query()
                ->whereIn('lpo_no', $lpoNos)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(function ($d) use ($lpoByNo, $statusNames, $paidByLpo) {
                    $lpo = $lpoByNo[$d->lpo_no] ?? null;
                    $total = $lpo ? (float) ($lpo->net_amount ?? $lpo->total_amount ?? 0) : 0;
                    $paid = (float) ($paidByLpo[$d->lpo_no] ?? 0);

                    return [
                        'id' => $d->id,
                        'lpo_no' => (int) $d->lpo_no,
                        'file_name' => $d->file_name,
                        'status_name' => $lpo ? ($statusNames[$lpo->lpo_status_code] ?? null) : null,
                        'supplier_invoice_no' => $lpo?->supplier_invoice_no,
                        'order_date' => $lpo?->created_at
                            ? Carbon::parse($lpo->created_at)->format('Y-m-d')
                            : null,
                        'total_amount' => $total,
                        'balance_due' => round(max(0, $total - $paid), 2),
                    ];
                });

        $leadDays = $lpos->map(function ($l) {
            if (! $l->created_at || ! $l->due_date) {
                return null;
            }
            $days = Carbon::parse($l->created_at)->diffInDays(Carbon::parse($l->due_date), false);

            return $days >= 0 ? $days : null;
        })->filter(fn ($d) => $d !== null);

        $lastDelivery = $lpos->filter(fn ($l) => $l->due_date)
            ->sortByDesc(fn ($l) => Carbon::parse($l->due_date))
            ->first();

        return [
            'supplier' => $supplier,
            'stats' => [
                'total_purchases' => round((float) $lpos->sum(fn ($l) => (float) ($l->total_amount ?? 0)), 2),
                'total_paid' => round((float) $payments->sum('amount_paid'), 2),
                'invoice_count' => $invoices->flatten()->count() ?: $lpos->count(),
                'open_lpo_count' => $purchases->where('balance_due', '>', 0)->count(),
                'last_delivery' => $lastDelivery?->due_date
                    ? Carbon::parse($lastDelivery->due_date)->format('Y-m-d')
                    : null,
                'average_lead_time_days' => $leadDays->isNotEmpty()
                    ? round($leadDays->avg(), 1)
                    : null,
            ],
            'purchases' => $purchases,
            'payments' => $payments,
            'documents' => $documents,
            'open_lpos' => $purchases
                ->filter(fn ($p) => $p['balance_due'] > 0)
                ->map(fn ($p) => [
                    'lpo_no' => $p['lpo_no'],
                    'balance_due' => $p['balance_due'],
                    'amount_paid' => $p['amount_paid'],
                    'total_amount' => $p['net_amount'] ?: $p['total_amount'],
                    'payment_status' => $p['payment_status'],
                    'supplier_invoice_no' => $p['supplier_invoice_no'],
                ])
                ->values(),
        ];
    }

    protected function paymentStatus(float $total, float $paid): string
    {
        if ($total <= 0) {
            return 'no_amount';
        }
        if ($paid <= 0) {
            return 'unpaid';
        }
        if ($paid + 0.01 >= $total) {
            return 'paid';
        }

        return 'partial';
    }

    protected function userLabel(?User $user): string
    {
        if (! $user) {
            return '—';
        }

        return $user->full_name ?: $user->username ?: '—';
    }
}
