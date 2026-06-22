<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CurrentStock;
use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;

class CurrentStockController extends Controller
{
    public function __construct(protected UserAccessService $access) {}

    public function index(Request $request)
    {
        $query = $this->scopedQuery($request);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, ['product_code', 'branch_id'], true) && $val !== null && $val !== '') {
                if ($col === 'branch_id') {
                    $this->access->assertBranchAccess($request->user(), (int) $val);
                }
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->paginate($perPage));
    }

    public function show(string $productCode, Request $request)
    {
        $branchId = (int) $request->query('branch_id', 0);
        abort_unless($branchId > 0, 422, 'branch_id query parameter is required');

        $user = $request->user();
        if ($user) {
            $this->access->assertBranchAccess($user, $branchId);
        }

        $row = $this->scopedQuery($request)
            ->where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->firstOrFail();

        return response()->json($row);
    }

    protected function scopedQuery(Request $request)
    {
        $query = CurrentStock::query();
        $user = $request->user();

        if ($user) {
            $orgId = $this->access->organizationId($user, $request);
            if ($orgId) {
                $branchIds = Branch::query()
                    ->where('organization_id', $orgId)
                    ->pluck('id');
                $query->whereIn('branch_id', $branchIds);
            }
            $this->access->scopeBranchIfLimited($query, $user);
        }

        return $query;
    }
}
