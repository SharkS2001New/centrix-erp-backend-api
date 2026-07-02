<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ParsesMultipartJsonFields;
use App\Http\Controllers\Controller;
use App\Models\SupplierReturnDocument;
use App\Services\Purchasing\SupplierReturnDocumentService;
use App\Services\Returns\ReturnProofService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SupplierReturnDocumentController extends Controller
{
    use ParsesMultipartJsonFields;

    public function __construct(
        protected SupplierReturnDocumentService $service,
        protected ReturnProofService $proofService,
    ) {}

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
        $this->decodeMultipartJsonFields($request, ['lines']);

        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'source_type' => 'required|in:lpo,manual',
            'lpo_no' => 'nullable|integer',
            'supplier_invoice_no' => 'nullable|string|max:100',
            'reason_scope' => 'nullable|in:order,per_product',
            'return_reason' => 'required|string|min:3',
            'notes' => 'nullable|string',
            'proof' => ReturnProofService::fileRules(),
            'lines' => 'required|array|min:1',
            'lines.*.product_code' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.package_type' => 'nullable|in:full_package,partial,pieces',
            'lines.*.uom_label' => 'nullable|string|max:45',
            'lines.*.stock_location' => 'nullable|in:shop,store',
            'lines.*.reason' => 'nullable|string',
        ]);

        $doc = $this->service->create($request->user(), $data, $request->file('proof'));

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
        $this->decodeMultipartJsonFields($request, ['lines']);

        $data = $request->validate([
            'supplier_id' => 'sometimes|integer|exists:suppliers,id',
            'branch_id' => 'sometimes|integer|exists:branches,id',
            'source_type' => 'sometimes|in:lpo,manual',
            'lpo_no' => 'nullable|integer',
            'supplier_invoice_no' => 'nullable|string|max:100',
            'reason_scope' => 'nullable|in:order,per_product',
            'return_reason' => 'sometimes|string|min:3',
            'notes' => 'nullable|string',
            'proof' => ReturnProofService::fileRules(),
            'lines' => 'sometimes|array|min:1',
            'lines.*.product_code' => 'required_with:lines|string',
            'lines.*.quantity' => 'required_with:lines|numeric|min:0.0001',
            'lines.*.package_type' => 'nullable|in:full_package,partial,pieces',
            'lines.*.uom_label' => 'nullable|string|max:45',
            'lines.*.stock_location' => 'nullable|in:shop,store',
            'lines.*.reason' => 'nullable|string',
        ]);

        $updated = $this->service->update($doc, $request->user(), $data, $request->file('proof'));

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

        app(\App\Services\Notifications\ActionRequestService::class)->markResolvedFromDomain(
            'supplier_return',
            'supplier_return_document',
            (int) $approved->id,
            'approved',
            $request->user(),
        );

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

        app(\App\Services\Notifications\ActionRequestService::class)->markResolvedFromDomain(
            'supplier_return',
            'supplier_return_document',
            (int) $rejected->id,
            'rejected',
            $request->user(),
            $data['rejection_reason'],
        );

        return response()->json([
            'data' => $this->service->formatDocument($rejected, $request->user()),
        ]);
    }

    public function proofFile(Request $request, string $id)
    {
        $doc = $this->service->findForUser($request->user(), (int) $id);
        $absolute = $this->proofService->absolutePath($doc);

        if ($absolute === null) {
            abort(Response::HTTP_NOT_FOUND, 'Proof file not found.');
        }

        return response()->file($absolute, [
            'Content-Type' => $doc->proof_file_mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.($doc->proof_file_name ?: 'proof').'"',
        ]);
    }
}
