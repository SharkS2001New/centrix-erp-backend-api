<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupplierReturnDocument;
use App\Services\Purchasing\SupplierReturnDocumentService;
use Illuminate\Http\Request;

class SupplierReturnDocumentController extends Controller
{
    public function __construct(protected SupplierReturnDocumentService $service) {}

    public function index(Request $request)
    {
        $filters = $request->only([
            'supplier_id', 'status', 'date_from', 'date_to', 'per_page',
        ]);

        $rows = $this->service->listForUser($request->user(), $filters);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'source_type' => 'required|in:lpo,manual',
            'lpo_no' => 'nullable|integer',
            'supplier_invoice_no' => 'nullable|string|max:100',
            'reason_scope' => 'nullable|in:order,per_product',
            'return_reason' => 'required|string|min:3',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_code' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.package_type' => 'nullable|in:full_package,partial,pieces',
            'lines.*.uom_label' => 'nullable|string|max:45',
            'lines.*.stock_location' => 'nullable|in:shop,store',
            'lines.*.reason' => 'nullable|string',
        ]);

        $doc = $this->service->create($request->user(), $data);

        return response()->json([
            'data' => $this->service->formatDocument($doc, $request->user()),
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $doc = $this->service->findForUser($request->user(), (int) $id);
        $doc->load(['lines', 'supplier', 'returnedByUser']);

        return response()->json([
            'data' => $this->service->formatDocument($doc, $request->user()),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $doc = $this->service->findForUser($request->user(), (int) $id);

        $data = $request->validate([
            'supplier_id' => 'sometimes|integer|exists:suppliers,id',
            'branch_id' => 'sometimes|integer|exists:branches,id',
            'source_type' => 'sometimes|in:lpo,manual',
            'lpo_no' => 'nullable|integer',
            'supplier_invoice_no' => 'nullable|string|max:100',
            'reason_scope' => 'nullable|in:order,per_product',
            'return_reason' => 'sometimes|string|min:3',
            'notes' => 'nullable|string',
            'lines' => 'sometimes|array|min:1',
            'lines.*.product_code' => 'required_with:lines|string',
            'lines.*.quantity' => 'required_with:lines|numeric|min:0.0001',
            'lines.*.package_type' => 'nullable|in:full_package,partial,pieces',
            'lines.*.uom_label' => 'nullable|string|max:45',
            'lines.*.stock_location' => 'nullable|in:shop,store',
            'lines.*.reason' => 'nullable|string',
        ]);

        $updated = $this->service->update($doc, $request->user(), $data);

        return response()->json([
            'data' => $this->service->formatDocument($updated, $request->user()),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $doc = $this->service->findForUser($request->user(), (int) $id);
        $this->service->deleteDocument($doc, $request->user());

        return response()->json(null, 204);
    }

    public function approve(Request $request, string $id)
    {
        $doc = $this->service->findForUser($request->user(), (int) $id);
        $approved = $this->service->approve($doc, $request->user());

        return response()->json([
            'data' => $this->service->formatDocument($approved, $request->user()),
        ]);
    }

    public function reject(Request $request, string $id)
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|min:3',
        ]);

        $doc = $this->service->findForUser($request->user(), (int) $id);
        $rejected = $this->service->reject($doc, $request->user(), $data['rejection_reason']);

        return response()->json([
            'data' => $this->service->formatDocument($rejected, $request->user()),
        ]);
    }
}
