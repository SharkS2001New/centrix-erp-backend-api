<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Supplier;
use App\Services\Accounting\SupplierPaymentJournalService;
use App\Services\Erp\ErpContext;
use App\Services\SupplierModuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends BaseResourceController
{
    public function __construct(
        protected SupplierModuleService $supplierModule,
        protected ErpContext $erp,
    ) {}

    protected function modelClass(): string
    {
        return Supplier::class;
    }

    protected function sortableColumns(): array
    {
        return [
            'supplier_name',
            'supplier_code',
            'contact_person',
            'phone',
            'town',
            'created_at',
        ];
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['supplier_name'] = 'required|string|max:255';
        $data = $request->validate($rules);
        $user = $request->user();

        $supplier = DB::transaction(function () use ($data, $user, $request) {
            if ($user && in_array('organization_id', $this->fillableFields(), true)) {
                $orgId = $this->access()->organizationId($user, $request);
                if ($orgId) {
                    $data['organization_id'] = $orgId;
                }
            }

            $organizationId = (int) ($data['organization_id'] ?? $user?->organization_id ?? 0);
            if ($organizationId < 1) {
                throw new \InvalidArgumentException('Organization is required to create a supplier.');
            }

            $data['organization_id'] = $organizationId;

            if (trim((string) ($data['supplier_code'] ?? '')) === '') {
                $data['supplier_code'] = Supplier::generateNextSupplierCode($organizationId);
            }

            if ($user) {
                $data['created_by'] = $user->id;
            }

            return Supplier::create($data);
        });

        if ($user && $this->auditable()) {
            $this->auditLogger()->logModel($user, 'create', $supplier, request: $request);
        }

        return response()->json($supplier, 201);
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
                    ->orWhere('terms_of_payment', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering($request, $query, 'supplier_name', 'asc');
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
        $payment->load(['paymentMethod', 'paidByUser', 'supplier']);

        app(SupplierPaymentJournalService::class)->postIfEnabled(
            $payment,
            $request->user(),
            $this->erp->gateForUser($request->user()),
        );

        return response()->json(
            $this->supplierModule->formatPayment($payment),
            201,
        );
    }
}
