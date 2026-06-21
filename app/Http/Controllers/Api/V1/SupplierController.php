<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Supplier;
use App\Services\SupplierModuleService;
use Illuminate\Http\Request;

class SupplierController extends BaseResourceController
{
    public function __construct(
        protected SupplierModuleService $supplierModule,
    ) {}

    protected function modelClass(): string
    {
        return Supplier::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);
        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('supplier_name', 'like', "%{$q}%")
                    ->orWhere('contact_person', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('alternate_phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('town', 'like', "%{$q}%")
                    ->orWhere('tax_pin', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $paginator = $query->paginate($perPage);

        $organizationId = (int) $request->user()->organization_id;
        $balances = $this->supplierModule->balancesForSuppliers(
            collect($paginator->items())->pluck('id'),
            $organizationId,
        );

        $paginator->getCollection()->transform(function (Supplier $supplier) use ($balances) {
            $supplier->setAttribute('current_balance', $balances->get($supplier->id, 0.0));

            return $supplier;
        });

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        $supplier = $this->findScopedModel($request, $id);
        $balance = $this->supplierModule->balancesForSuppliers(
            collect([$supplier->id]),
            (int) $request->user()->organization_id,
        )->get($supplier->id, 0.0);
        $supplier->setAttribute('current_balance', $balance);

        return response()->json($supplier);
    }

    public function dashboard(Request $request)
    {
        return response()->json(
            $this->supplierModule->dashboard((int) $request->user()->organization_id),
        );
    }

    public function recalculateBalances(Request $request)
    {
        return response()->json([
            'message' => 'Supplier balances are computed from LPO receipts and payments.',
            'recalculated' => true,
        ]);
    }

    public function summary(Request $request, string $supplier)
    {
        $model = $this->findScopedModel($request, $supplier);

        return response()->json($this->supplierModule->summary($model));
    }

    public function storePayment(Request $request, string $supplier)
    {
        $model = $this->findScopedModel($request, $supplier);
        $payment = $this->supplierModule->recordPayment($request, $model);

        return response()->json(
            $this->supplierModule->formatPayment($payment->load(['paymentMethod', 'paidByUser', 'supplier'])),
            201,
        );
    }
}
