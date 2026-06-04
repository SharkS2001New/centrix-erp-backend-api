<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PayrollLine;
use Illuminate\Http\Request;

class PayrollLineController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return PayrollLine::class;
    }

    public function index(Request $request)
    {
        $query = PayrollLine::query()->with('employee');

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 50), 500);

        return response()->json($query->orderBy('id')->paginate($perPage));
    }

    public function show(string $id)
    {
        $line = PayrollLine::with('employee')->findOrFail($id);

        return response()->json($line);
    }
}
