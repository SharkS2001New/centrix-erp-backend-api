<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoMst;
use App\Models\LpoStatus;
use App\Models\LpoTxn;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Erp\ErpContext;
use App\Services\LpoModuleService;
use App\Services\Purchasing\LpoNumberAllocator;
use App\Services\Purchasing\LpoWorkflowService;
use App\Services\Purchasing\ProcurementSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LpoMstController extends BaseResourceController
{
    public function __construct(
        protected LpoModuleService $lpoModule,
        protected LpoWorkflowService $workflow,
        protected LpoNumberAllocator $lpoNumbers,
        protected ErpContext $erp,
    ) {}

    protected function modelClass(): string
    {
        return LpoMst::class;
    }

    protected function routeKeyColumn(): string
    {
        return 'lpo_no';
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)->whereNull('deleted_at');
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('status_code')) {
            $query->where('lpo_status_code', $request->input('status_code'));
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $query->where(function ($inner) use ($q) {
                $inner->where('lpo_no', 'like', "%{$q}%")
                    ->orWhere('reference_number', 'like', "%{$q}%")
                    ->orWhereHas('supplier', function ($supplier) use ($q) {
                        $supplier->where('supplier_name', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $orgId = (int) ($request->user()?->organization_id ?? 0);

        $paginator = $query->with('supplier')->orderByDesc('lpo_no')->paginate($perPage);
        $mapped = $this->lpoModule->mapListRows($paginator->getCollection(), $orgId);
        $paginator->setCollection(collect($mapped));

        return response()->json($paginator);
    }

    public function dashboard(Request $request)
    {
        $startOfMonth = now()->startOfMonth();
        $base = $this->baseQuery($request);

        $monthly = (clone $base)->where(function ($q) use ($startOfMonth) {
            $q->where('created_at', '>=', $startOfMonth)
                ->orWhere('sent_at', '>=', $startOfMonth);
        });

        $pendingStatuses = [0, 1, 2, 3, 4];

        return response()->json([
            'total_pos' => (clone $monthly)->count(),
            'total_value' => (float) ((clone $monthly)->sum('total_amount') ?? 0),
            'pending_count' => (clone $base)->whereIn('lpo_status_code', $pendingStatuses)->count(),
            'cleared_count' => (clone $base)->where(function ($q) {
                $q->where('lpo_status_code', 5)->orWhere('cleared_flag', 1);
            })->count(),
            'partially_received_count' => (clone $base)->where('lpo_status_code', 4)->count(),
        ]);
    }

    public function summary(Request $request, string $lpoNo)
    {
        $orgId = (int) ($request->user()?->organization_id ?? 0);

        return response()->json($this->lpoModule->summary((int) $lpoNo, $orgId));
    }

    public function show(Request $request, string $id)
    {
        $model = $this->baseQuery($request)->where($this->routeKeyColumn(), $id)->firstOrFail();

        return response()->json($model);
    }

    public function storeFull(Request $request)
    {
        $payload = $this->validateFullPayload($request);
        $user = $request->user();
        $org = Organization::findOrFail($user->organization_id);
        $settings = ProcurementSettingsResolver::forOrganization($org);

        $lpo = DB::transaction(function () use ($payload, $user, $settings, $org) {
            Supplier::query()
                ->where('id', $payload['supplier_id'])
                ->where('organization_id', $org->id)
                ->firstOrFail();

            $totals = $this->computeTotals($payload['lines']);
            $dueDate = $payload['due_date']
                ?? now()->addDays($settings['default_payment_terms_days'])->toDateString();

            $lpo = LpoMst::create([
                'organization_id' => $org->id,
                'lpo_seq' => $this->lpoNumbers->nextForOrganization((int) $org->id),
                'supplier_id' => $payload['supplier_id'],
                'reference_number' => $payload['reference_number'] ?? null,
                'due_date' => $dueDate,
                'delivery_address' => $payload['delivery_address'] ?? null,
                'terms' => $payload['terms'] ?? null,
                'instructions' => $payload['instructions'] ?? null,
                'lpo_status_code' => $payload['lpo_status_code'] ?? LpoWorkflowService::STATUS_AWAITING_CHECK,
                'total_amount' => $totals['total'],
                'vat_amount' => $totals['vat'],
                'net_amount' => $totals['total'],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            foreach ($payload['lines'] as $line) {
                LpoTxn::create([
                    'lpo_no' => $lpo->lpo_no,
                    'product_code' => $line['product_code'],
                    'ordered_qty' => $line['ordered_qty'],
                    'cost_price' => $line['cost_price'],
                    'uom' => $line['uom'] ?? null,
                    'received_qty' => 0,
                ]);
            }

            return $lpo;
        });

        return response()->json([
            'lpo' => $lpo,
            'lpo_no' => $lpo->lpo_no,
        ], 201);
    }

    public function updateFull(Request $request, string $lpoNo)
    {
        $lpo = $this->baseQuery($request)->where($this->routeKeyColumn(), $lpoNo)->firstOrFail();
        if ((int) $lpo->lpo_status_code >= LpoModuleService::STATUS_AWAITING_RECEIVE) {
            throw ValidationException::withMessages([
                'lpo' => ['This LPO cannot be edited after it has been sent to the supplier.'],
            ]);
        }

        $payload = $this->validateFullPayload($request, updating: true);

        DB::transaction(function () use ($lpo, $payload) {
            $totals = $this->computeTotals($payload['lines']);

            $lpo->update([
                'supplier_id' => $payload['supplier_id'],
                'reference_number' => $payload['reference_number'] ?? null,
                'due_date' => $payload['due_date'] ?? $lpo->due_date,
                'delivery_address' => $payload['delivery_address'] ?? null,
                'terms' => $payload['terms'] ?? null,
                'instructions' => $payload['instructions'] ?? null,
                'lpo_status_code' => $payload['lpo_status_code'] ?? $lpo->lpo_status_code,
                'total_amount' => $totals['total'],
                'vat_amount' => $totals['vat'],
                'net_amount' => $totals['total'],
            ]);

            LpoTxn::query()->where('lpo_no', $lpo->lpo_no)->delete();
            foreach ($payload['lines'] as $line) {
                LpoTxn::create([
                    'lpo_no' => $lpo->lpo_no,
                    'product_code' => $line['product_code'],
                    'ordered_qty' => $line['ordered_qty'],
                    'cost_price' => $line['cost_price'],
                    'uom' => $line['uom'] ?? null,
                    'received_qty' => 0,
                ]);
            }
        });

        return response()->json($this->lpoModule->summary((int) $lpoNo, (int) $request->user()->organization_id));
    }

    public function workflow(Request $request, string $lpoNo)
    {
        $data = $request->validate([
            'action' => 'required|in:mark_checked,approve,mark_sent',
        ]);

        $lpo = $this->baseQuery($request)->where($this->routeKeyColumn(), $lpoNo)->firstOrFail();
        $org = Organization::findOrFail($request->user()->organization_id);
        $updated = $this->workflow->applyAction($lpo, $data['action'], $request->user(), $org);

        return response()->json($this->lpoModule->summary((int) $updated->lpo_no, (int) $org->id));
    }

    public function update(Request $request, string $id)
    {
        $model = $this->baseQuery($request)->where($this->routeKeyColumn(), $id)->firstOrFail();
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $model->update($request->validate($rules));

        return response()->json($model);
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->baseQuery($request)->where($this->routeKeyColumn(), $id)->firstOrFail();
        $model->update([
            'deleted_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    protected function validateFullPayload(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'supplier_id' => $req.'integer|exists:suppliers,id',
            'reference_number' => 'nullable|string|max:120',
            'due_date' => 'nullable|date',
            'delivery_address' => 'nullable|string|max:500',
            'terms' => 'nullable|string|max:2000',
            'instructions' => 'nullable|string|max:2000',
            'lpo_status_code' => 'nullable|integer|min:0|max:7',
            'lines' => $req.'array|min:1',
            'lines.*.product_code' => 'required|string|max:50',
            'lines.*.ordered_qty' => 'required|numeric|min:0.001',
            'lines.*.cost_price' => 'required|numeric|min:0',
            'lines.*.uom' => 'nullable|string|max:50',
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $lines
     * @return array{subtotal: float, vat: float, total: float}
     */
    protected function computeTotals(array $lines): array
    {
        $subtotal = 0.0;
        $vat = 0.0;

        foreach ($lines as $line) {
            $product = Product::with('vat')->where('product_code', $line['product_code'])->first();
            $qty = (float) $line['ordered_qty'];
            $cost = (float) $line['cost_price'];
            $net = $qty * $cost;
            $rate = (float) ($product?->vat?->vat_percentage ?? 0);
            $subtotal += $net;
            $vat += $net * ($rate / 100);
        }

        $subtotal = round($subtotal, 2);
        $vat = round($vat, 2);

        return [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => round($subtotal + $vat, 2),
        ];
    }
}
