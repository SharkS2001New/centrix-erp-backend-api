<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LpoMst;
use App\Services\LpoModuleService;
use App\Services\SupplierBalanceService;
use Illuminate\Http\Request;

class LpoMstController extends BaseResourceController
{
    public function __construct(
        protected SupplierBalanceService $supplierBalances,
        protected LpoModuleService $lpoModule,
    ) {}

    protected function modelClass(): string
    {
        return LpoMst::class;
    }

    protected function routeKeyColumn(): string
    {
        return 'lpo_no';
    }

    public function index(Request $request)
    {
        return response()->json($this->lpoModule->index($request));
    }

    public function dashboard(Request $request)
    {
        return response()->json($this->lpoModule->dashboard($request->user()?->organization_id));
    }

    public function summary(string $lpo_mst)
    {
        return response()->json($this->lpoModule->detail((int) $lpo_mst));
    }

    public function storeFull(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'reference_number' => 'nullable|string|max:45',
            'due_date' => 'nullable|date',
            'delivery_address' => 'nullable|string|max:45',
            'lpo_status_code' => 'nullable|integer',
            'terms' => 'nullable|string|max:200',
            'instructions' => 'nullable|string|max:200',
            'lines' => 'required|array|min:1',
            'lines.*.product_code' => 'required|string',
            'lines.*.ordered_qty' => 'required|numeric|min:0.001',
            'lines.*.cost_price' => 'nullable|numeric|min:0',
            'lines.*.uom' => 'nullable|string|max:45',
            'lines.*.received_qty' => 'nullable|numeric|min:0',
        ]);

        $lpo = $this->lpoModule->saveWithLines(
            $data,
            $data['lines'],
            (int) $request->user()->id,
        );

        return response()->json($this->lpoModule->detail((int) $lpo->lpo_no), 201);
    }

    public function updateFull(Request $request, string $lpo_mst)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'reference_number' => 'nullable|string|max:45',
            'due_date' => 'nullable|date',
            'delivery_address' => 'nullable|string|max:45',
            'lpo_status_code' => 'nullable|integer',
            'terms' => 'nullable|string|max:200',
            'instructions' => 'nullable|string|max:200',
            'lines' => 'required|array|min:1',
            'lines.*.product_code' => 'required|string',
            'lines.*.ordered_qty' => 'required|numeric|min:0.001',
            'lines.*.cost_price' => 'nullable|numeric|min:0',
            'lines.*.uom' => 'nullable|string|max:45',
            'lines.*.received_qty' => 'nullable|numeric|min:0',
        ]);

        $this->lpoModule->saveWithLines(
            $data,
            $data['lines'],
            (int) $request->user()->id,
            (int) $lpo_mst,
        );

        return response()->json($this->lpoModule->detail((int) $lpo_mst));
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $model = LpoMst::create($request->validate($rules));
        if (! $model->created_at) {
            $model->update(['created_at' => now()]);
        }
        $this->syncSupplierBalance($model);

        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = LpoMst::where($this->routeKeyColumn(), $id)->firstOrFail();
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $model->update($request->validate($rules));
        $this->syncSupplierBalance($model->fresh());

        return response()->json($model);
    }

    public function destroy(string $id)
    {
        $this->lpoModule->delete((int) $id, (int) request()->user()->id);

        return response()->json(['message' => 'LPO removed.']);
    }

    public function workflow(Request $request, string $lpo_mst)
    {
        $data = $request->validate([
            'action' => 'required|string|in:mark_checked,approve,mark_sent',
        ]);

        $this->lpoModule->transition((int) $lpo_mst, $data['action'], (int) $request->user()->id);

        return response()->json($this->lpoModule->detail((int) $lpo_mst));
    }

    protected function syncSupplierBalance(LpoMst $lpo): void
    {
        if ($lpo->supplier_id) {
            $this->supplierBalances->recalculate((int) $lpo->supplier_id);
        }
    }
}
