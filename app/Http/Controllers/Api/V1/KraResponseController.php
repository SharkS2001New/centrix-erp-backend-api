<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\KraResponse;
use Illuminate\Http\Request;

class KraResponseController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return KraResponse::class;
    }

    protected function baseQuery(Request $request)
    {
        $query = KraResponse::query();
        $user = $request->user();
        $orgId = $this->access()->organizationId($user, $request);

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        if ($user) {
            $branchId = $this->access()->branchId($user);
            if ($branchId !== null) {
                $query->whereHas('sale', fn ($saleQuery) => $saleQuery->where('branch_id', $branchId));
            }
        }

        return $query;
    }

    public function index(Request $request)
    {
        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'status' => 'nullable|in:pending,success,failed',
        ]);

        $query = $this->baseQuery($request);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date').' 23:59:59');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('order_no', 'like', "%{$q}%")
                    ->orWhere('invoice_number', 'like', "%{$q}%")
                    ->orWhere('sale_id', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)
        );
    }
}
