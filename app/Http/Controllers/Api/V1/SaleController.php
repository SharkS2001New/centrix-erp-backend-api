<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use Illuminate\Http\Request;

class SaleController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return Sale::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        if ($request->boolean('with_items')) {
            $query->with(['items.product.unit']);
        }

        $query->with(['cashier:id,username,full_name']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($exclude = $request->input('exclude_status')) {
            $query->where('status', '!=', $exclude);
        }

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('order_num', 'like', "%{$q}%")
                    ->orWhere('customer_name_override', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function show(Request $request, string $id)
    {
        $sale = $this->baseQuery($request)->with(['items.product.unit'])->findOrFail($id);
        $gate = $this->erp->gateForUser($request->user());
        $channel = $sale->channel ?: 'backend';
        $workflow = OrderWorkflowService::forGate($gate)->forChannel($channel);

        return response()->json(array_merge($sale->toArray(), [
            'workflow' => $workflow,
        ]));
    }
}
