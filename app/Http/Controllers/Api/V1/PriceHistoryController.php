<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PriceHistory;
use Illuminate\Http\Request;

class PriceHistoryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return PriceHistory::class;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $days = max(0, (int) $request->input('days', 7));
        $perPage = min((int) $request->input('per_page', 25), 200);

        $query = PriceHistory::query();
        if ($orgId = $user?->organization_id) {
            $query->where('organization_id', $orgId);
        }
        if ($days > 0) {
            $query->where('changed_at', '>=', now()->subDays($days));
        }
        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }
        if ($q = $request->input('q')) {
            $query->where('product_code', 'like', "%{$q}%");
        }

        $paginated = $query->orderByDesc('changed_at')->paginate($perPage);
        $items = collect($paginated->items());

        if ($items->isNotEmpty()) {
            $productCodes = $items->pluck('product_code')->unique()->values();
            $orgScope = $user?->organization_id;

            $priorHistory = PriceHistory::query()
                ->when($orgScope, fn ($q) => $q->where('organization_id', $orgScope))
                ->whereIn('product_code', $productCodes)
                ->orderBy('changed_at')
                ->orderBy('id')
                ->get(['id', 'product_code', 'unit_price', 'changed_at']);

            $grouped = $priorHistory->groupBy('product_code');

            $items->transform(function ($row) use ($grouped) {
                $history = $grouped->get($row->product_code, collect());
                $prev = $history
                    ->filter(
                        fn ($h) => $h->id !== $row->id
                            && ($h->changed_at < $row->changed_at
                                || ($h->changed_at->eq($row->changed_at) && $h->id < $row->id)),
                    )
                    ->sortByDesc(fn ($h) => [$h->changed_at, $h->id])
                    ->first();

                $row->previous_unit_price = $prev !== null ? (float) $prev->unit_price : null;

                return $row;
            });
        }

        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'product_code' => 'required|string|exists:products,product_code',
            'unit_price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'discount_pct' => 'nullable|numeric|min:0',
            'changed_at' => 'nullable|date',
        ]);

        $row = PriceHistory::create([
            'product_code' => $data['product_code'],
            'unit_price' => $data['unit_price'],
            'cost_price' => $data['cost_price'],
            'discount_pct' => $data['discount_pct'] ?? 0,
            'changed_by' => $user->id,
            'organization_id' => $user->organization_id,
            'changed_at' => $data['changed_at'] ?? now(),
        ]);

        return response()->json($row, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = PriceHistory::findOrFail($id);
        $data = $request->validate([
            'product_code' => 'sometimes|string|exists:products,product_code',
            'unit_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'discount_pct' => 'nullable|numeric|min:0',
            'changed_at' => 'nullable|date',
        ]);

        $model->update($data);

        return response()->json($model);
    }
}
