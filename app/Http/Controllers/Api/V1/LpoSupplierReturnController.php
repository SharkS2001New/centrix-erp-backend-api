<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LpoMst;
use App\Services\SupplierReturnDocumentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LpoSupplierReturnController extends Controller
{
    public function __construct(
        protected SupplierReturnDocumentService $documents,
    ) {}

    public function index(string $lpo_mst)
    {
        return response()->json([
            'data' => $this->documents->listForLpo((int) $lpo_mst),
        ]);
    }

    public function store(Request $request, string $lpo_mst)
    {
        $lpoNo = (int) $lpo_mst;
        $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo)->firstOrFail();

        $data = $request->validate([
            'branch_id' => 'required|integer',
            'product_code' => 'required|string|max:200',
            'quantity' => 'required|numeric|min:0.001',
            'package_type' => 'nullable|in:full_package,partial,pieces',
            'uom_label' => 'nullable|string|max:45',
            'stock_location' => 'nullable|in:shop,store',
            'reason' => 'required|string|max:2000',
        ]);

        try {
            $this->documents->create([
                'supplier_id' => (int) $lpo->supplier_id,
                'branch_id' => (int) $data['branch_id'],
                'source_type' => 'lpo',
                'lpo_no' => $lpoNo,
                'notes' => $data['reason'],
                'lines' => [[
                    'product_code' => $data['product_code'],
                    'quantity' => (float) $data['quantity'],
                    'package_type' => $data['package_type'] ?? 'partial',
                    'uom_label' => $data['uom_label'] ?? null,
                    'stock_location' => $data['stock_location'] ?? 'store',
                ]],
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->documents->listForLpo($lpoNo),
        ], 201);
    }
}
