<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockReservation;
use Illuminate\Http\Request;

class StockReservationController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return StockReservation::class;
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)
            ->with([
                'product:product_code,product_name',
                'sale:id,order_num,status,organization_id',
            ]);
    }

    protected function searchColumns(): array
    {
        return ['product_code'];
    }

    /** @return array<string, mixed> */
    protected function presentReservation(StockReservation $row): array
    {
        $payload = $row->toArray();
        $payload['product_name'] = $row->product?->product_name ?? $row->product_code;
        unset($payload['product']);
        // Keep a lean sale stub for order labels without a second round-trip.
        if ($row->relationLoaded('sale') && $row->sale) {
            $payload['sale'] = [
                'id' => (int) $row->sale->id,
                'order_num' => $row->sale->order_num,
                'status' => $row->sale->status,
            ];
        } else {
            unset($payload['sale']);
        }

        return $payload;
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
                $inner->where('product_code', 'like', "%{$q}%")
                    ->orWhereHas(
                        'product',
                        fn ($product) => $product->where('product_name', 'like', "%{$q}%"),
                    );
            });
        }

        $this->applyCreatedAtDateRange($query, $request);
        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering(
            $request,
            $query,
            $this->defaultListOrderColumn(),
            $this->defaultListOrderDirection(),
        );

        return response()->json(
            $query->paginate($perPage)->through(
                fn (StockReservation $row) => $this->presentReservation($row),
            ),
        );
    }

    public function show(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        return response()->json($this->presentReservation($model));
    }
}
