<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Supplier;
use App\Services\SupplierBalanceService;
use App\Services\SupplierSummaryService;
use Illuminate\Http\Request;

class SupplierController extends BaseResourceController
{
    public function __construct(
        protected SupplierSummaryService $summaryService,
        protected SupplierBalanceService $supplierBalances,
    ) {}

    protected function modelClass(): string
    {
        return Supplier::class;
    }

    public function index(Request $request)
    {
        $query = Supplier::query()->whereNull('deleted_at');

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $status = $request->input('status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('supplier_name', 'like', "%{$q}%")
                    ->orWhere('supplier_code', 'like', "%{$q}%")
                    ->orWhere('contact_person', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderBy('supplier_name')->paginate($perPage),
        );
    }

    /** POST /suppliers/recalculate-balances — sync amount owing from LPOs and payments */
    public function recalculateBalances(Request $request)
    {
        $query = Supplier::query()->whereNull('deleted_at');
        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        $count = 0;
        foreach ($query->pluck('id') as $id) {
            $this->supplierBalances->recalculate((int) $id);
            $count++;
        }

        return response()->json(['message' => 'Supplier balances updated.', 'count' => $count]);
    }

    /** GET /suppliers/dashboard */
    public function dashboard(Request $request)
    {
        $query = Supplier::query()->whereNull('deleted_at');
        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        $suppliers = (clone $query)->get(['id', 'is_active', 'current_balance']);

        $amountOwing = round($suppliers->sum(fn ($s) => max(0, (float) $s->current_balance)), 2);

        return response()->json([
            'total' => $suppliers->count(),
            'active' => $suppliers->where('is_active', true)->count(),
            'amount_owing' => $amountOwing,
            'credit_due' => $amountOwing,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->user()?->organization_id && empty($data['organization_id'])) {
            $data['organization_id'] = $request->user()->organization_id;
        }
        if (empty($data['supplier_code'])) {
            $data['supplier_code'] = $this->nextSupplierCode();
        }
        if ($request->user()?->id) {
            $data['created_by'] = $request->user()->id;
        }
        if (! array_key_exists('current_balance', $data) || $data['current_balance'] === null) {
            $data['current_balance'] = $data['opening_balance'] ?? 0;
        }

        $supplier = Supplier::create($data);
        $this->supplierBalances->recalculate($supplier->id);

        return response()->json($supplier->fresh(), 201);
    }

    public function show(string $id)
    {
        return response()->json(
            Supplier::query()->whereNull('deleted_at')->findOrFail((int) $id),
        );
    }

    public function update(Request $request, string $id)
    {
        $supplier = Supplier::query()->whereNull('deleted_at')->findOrFail((int) $id);
        $supplier->update($this->validated($request, partial: true));

        return response()->json($supplier->fresh());
    }

    public function destroy(string $id)
    {
        $supplier = Supplier::query()->whereNull('deleted_at')->findOrFail((int) $id);
        $supplier->update([
            'deleted_at' => now(),
            'deleted_by' => request()->user()?->id,
            'is_active' => false,
        ]);

        return response()->json(['message' => 'Supplier removed.']);
    }

    /** GET /suppliers/{id}/summary — profile stats and tab data */
    public function summary(Request $request, string $id)
    {
        return response()->json($this->summaryService->build((int) $id));
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : '';

        return $request->validate([
            'supplier_code' => $prefix . 'string|max:50',
            'supplier_name' => $prefix . 'string|max:200',
            'contact_person' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:45',
            'alternate_phone' => 'nullable|string|max:45',
            'address' => 'nullable|string',
            'town' => 'nullable|string|max:100',
            'tax_pin' => 'nullable|string|max:45',
            'additional_info' => 'nullable|string',
            'contacts' => 'nullable|array',
            'contacts.*.label' => 'nullable|string|max:100',
            'contacts.*.phone' => 'nullable|string|max:45',
            'contacts.*.email' => 'nullable|email|max:100',
            'organization_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'credit_limit' => 'nullable|numeric|min:0',
            'opening_balance' => 'nullable|numeric|min:0',
            'current_balance' => 'nullable|numeric|min:0',
        ]);
    }

    protected function nextSupplierCode(): string
    {
        $maxId = (int) Supplier::query()->max('id');

        return 'SUP-' . str_pad((string) ($maxId + 1), 3, '0', STR_PAD_LEFT);
    }
}
