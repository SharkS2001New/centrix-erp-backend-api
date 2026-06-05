<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\SupplierPaymentService;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    public function __construct(
        protected SupplierPaymentService $payments,
    ) {}

    /** GET /supplier-payments — all supplier payments (module list) */
    public function indexAll(Request $request)
    {
        $query = SupplierPayment::query()->orderByDesc('date_paid')->orderByDesc('id');

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        if ($supplierId = $request->input('supplier_id')) {
            $query->where('supplier_id', (int) $supplierId);
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('date_paid', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('date_paid', '<=', $to);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->paginate($perPage);

        $supplierIds = collect($paginated->items())->pluck('supplier_id')->unique();
        $methodIds = collect($paginated->items())->pluck('payment_method_id')->unique();
        $payerIds = collect($paginated->items())->pluck('paid_by')->unique();

        $suppliers = Supplier::query()->whereIn('id', $supplierIds)->get()->keyBy('id');
        $methods = PaymentMethod::query()->whereIn('id', $methodIds)->pluck('method_name', 'id');
        $payers = User::query()->whereIn('id', $payerIds)->get()->keyBy('id');

        $paginated->getCollection()->transform(function ($payment) use ($suppliers, $methods, $payers) {
            $supplier = $suppliers[$payment->supplier_id] ?? null;
            $payer = $payers[$payment->paid_by] ?? null;

            $snapshot = (float) ($payment->amount_due_snapshot ?? 0);
            $paid = (float) $payment->amount_paid;

            return [
                'id' => $payment->id,
                'supplier_id' => $payment->supplier_id,
                'supplier_name' => $supplier?->supplier_name,
                'lpo_no' => $payment->lpo_no,
                'amount_paid' => $paid,
                'amount_due_snapshot' => $snapshot > 0 ? $snapshot : null,
                'is_partial' => $snapshot > 0 && $paid + 0.01 < $snapshot,
                'date_paid' => $payment->date_paid?->format('Y-m-d'),
                'payment_method' => $methods[$payment->payment_method_id] ?? '—',
                'payment_method_id' => $payment->payment_method_id,
                'reference_number' => $payment->reference_number,
                'cheque_number' => $payment->cheque_number,
                'notes' => $payment->notes,
                'paid_by_name' => $payer?->full_name ?: $payer?->username ?: '—',
            ];
        });

        return response()->json($paginated);
    }

    /** GET /suppliers/{supplier}/payments */
    public function index(Request $request, string $supplier)
    {
        Supplier::query()->whereNull('deleted_at')->findOrFail((int) $supplier);

        $rows = SupplierPayment::query()
            ->where('supplier_id', (int) $supplier)
            ->orderByDesc('date_paid')
            ->orderByDesc('id')
            ->limit(min((int) $request->input('per_page', 50), 200))
            ->get();

        return response()->json(['data' => $rows]);
    }

    /** POST /suppliers/{supplier}/payments */
    public function store(Request $request, string $supplier)
    {
        $supplierModel = Supplier::query()->whereNull('deleted_at')->findOrFail((int) $supplier);

        $data = $request->validate([
            'lpo_no' => 'nullable|integer',
            'lpo_supplier_invoice_id' => 'nullable|integer',
            'payment_method_id' => 'required|integer',
            'amount_paid' => 'required|numeric|min:0.01',
            'amount_due_snapshot' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:100',
            'cheque_number' => 'nullable|string|max:45',
            'date_paid' => 'required|date',
            'notes' => 'nullable|string',
            'manual_amount' => 'nullable|boolean',
            'declared_payable' => 'nullable|numeric|min:0.01',
        ]);

        $orgId = $request->user()?->organization_id ?? $supplierModel->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization is required for supplier payments.'], 422);
        }

        $result = $this->payments->record([
            ...$data,
            'supplier_id' => $supplierModel->id,
            'paid_by' => $request->user()->id,
            'organization_id' => $orgId,
        ]);

        return response()->json([
            ...$result['payment']->toArray(),
            'balance_before' => $result['balance_before'],
            'balance_after' => $result['balance_after'],
            'is_partial' => $result['is_partial'],
        ], 201);
    }
}
