<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CurrentStock;
use Illuminate\Http\Request;

class CurrentStockController extends Controller
{
    public function index(Request $request)
    {
        $query = CurrentStock::query();
        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, ['product_code', 'branch_id'], true)) {
                $query->where($col, $val);
            }
        }
        $perPage = min((int) $request->input('per_page', 25), 200);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_code' => 'required|string',
            'branch_id' => 'required|integer',
            'shop_quantity' => 'nullable|numeric',
            'store_quantity' => 'nullable|numeric',
        ]);
        $row = CurrentStock::updateOrCreate(
            ['product_code' => $data['product_code'], 'branch_id' => $data['branch_id']],
            $data
        );
        return response()->json($row, 201);
    }

    public function show(string $productCode, Request $request)
    {
        $branchId = $request->query('branch_id');
        abort_unless($branchId, 422, 'branch_id query parameter is required');
        $row = CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->firstOrFail();
        return response()->json($row);
    }

    public function update(Request $request, string $productCode)
    {
        $branchId = $request->input('branch_id', $request->query('branch_id'));
        abort_unless($branchId, 422, 'branch_id is required');
        $row = CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->firstOrFail();
        $data = $request->validate([
            'shop_quantity' => 'nullable|numeric',
            'store_quantity' => 'nullable|numeric',
        ]);
        $row->update($data);
        return response()->json($row);
    }

    public function destroy(string $productCode, Request $request)
    {
        $branchId = $request->query('branch_id');
        abort_unless($branchId, 422, 'branch_id query parameter is required');
        CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->firstOrFail()
            ->delete();
        return response()->json(null, 204);
    }
}
