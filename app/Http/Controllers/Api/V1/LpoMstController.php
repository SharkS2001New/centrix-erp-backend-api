<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoMst;
use App\Models\LpoStatus;
use App\Models\Supplier;
use App\Services\LpoModuleService;
use Illuminate\Http\Request;

class LpoMstController extends BaseResourceController
{
    public function __construct(
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

    protected function baseQuery()
    {
        return LpoMst::query()->whereNull('deleted_at');
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery();

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

        return response()->json($query->orderByDesc('lpo_no')->paginate($perPage));
    }

    public function dashboard()
    {
        $startOfMonth = now()->startOfMonth();
        $base = $this->baseQuery();

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
                $q->where('lpo_status_code', 6)->orWhere('cleared_flag', 1);
            })->count(),
            'partially_received_count' => (clone $base)->where('lpo_status_code', 4)->count(),
        ]);
    }

    public function summary(string $lpoNo)
    {
        return response()->json($this->lpoModule->summary((int) $lpoNo));
    }

    public function show(string $id)
    {
        $model = $this->baseQuery()->where($this->routeKeyColumn(), $id)->firstOrFail();

        return response()->json($model);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->baseQuery()->where($this->routeKeyColumn(), $id)->firstOrFail();
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $model->update($request->validate($rules));

        return response()->json($model);
    }

    public function destroy(string $id)
    {
        $model = $this->baseQuery()->where($this->routeKeyColumn(), $id)->firstOrFail();
        $model->update([
            'deleted_at' => now(),
        ]);

        return response()->json(null, 204);
    }
}
