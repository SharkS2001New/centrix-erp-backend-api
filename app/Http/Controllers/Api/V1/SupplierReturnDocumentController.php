<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupplierReturnDocumentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SupplierReturnDocumentController extends Controller
{
    public function __construct(
        protected SupplierReturnDocumentService $documents,
    ) {}

    public function index(Request $request)
    {
        return response()->json(
            $this->documents->paginatedList($request, $request->user()),
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateDocumentPayload($request);

        try {
            $doc = $this->documents->create($data, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $doc], 201);
    }

    public function show(string $supplier_return_document, Request $request)
    {
        return response()->json([
            'data' => $this->documents->show((int) $supplier_return_document, $request->user()),
        ]);
    }

    public function update(Request $request, string $supplier_return_document)
    {
        $data = $this->validateDocumentPayload($request, partial: true);

        try {
            $doc = $this->documents->update((int) $supplier_return_document, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $doc]);
    }

    public function destroy(string $supplier_return_document, Request $request)
    {
        try {
            $this->documents->delete((int) $supplier_return_document, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }

        return response()->json(null, 204);
    }

    public function approve(Request $request, string $supplier_return_document)
    {
        try {
            $doc = $this->documents->approve((int) $supplier_return_document, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $doc]);
    }

    public function reject(Request $request, string $supplier_return_document)
    {
        $data = $request->validate([
            'rejection_reason' => 'required_without:reason|string|max:500',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $doc = $this->documents->reject((int) $supplier_return_document, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['document' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $doc]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateDocumentPayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'supplier_id' => ($partial ? 'sometimes' : 'required').'|integer',
            'branch_id' => ($partial ? 'sometimes' : 'required').'|integer',
            'source_type' => ($partial ? 'sometimes' : 'required').'|in:manual,lpo',
            'lpo_no' => 'nullable|integer',
            'supplier_invoice_no' => 'nullable|string|max:120',
            'reason_scope' => 'nullable|in:order,per_product',
            'return_reason' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'reason' => 'nullable|string|max:2000',
            'lines' => ($partial ? 'sometimes' : 'required').'|array|min:1',
            'lines.*.product_code' => 'required_with:lines|string|max:200',
            'lines.*.quantity' => 'required_with:lines|numeric|min:0.001',
            'lines.*.package_type' => 'nullable|in:full_package,partial,pieces',
            'lines.*.uom_label' => 'nullable|string|max:45',
            'lines.*.stock_location' => 'nullable|in:shop,store',
            'lines.*.reason' => 'nullable|string|max:2000',
        ];

        $data = $request->validate($rules);
        $this->assertReturnReasonPresent($data, $partial);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertReturnReasonPresent(array $data, bool $partial): void
    {
        $scope = $data['reason_scope'] ?? 'order';
        if ($scope !== 'order') {
            return;
        }

        if ($partial && ! array_key_exists('return_reason', $data) && ! array_key_exists('notes', $data) && ! array_key_exists('reason', $data) && ! array_key_exists('reason_scope', $data)) {
            return;
        }

        $notes = trim((string) ($data['return_reason'] ?? $data['notes'] ?? $data['reason'] ?? ''));
        if (strlen($notes) < 3) {
            throw ValidationException::withMessages([
                'return_reason' => ['Enter a return reason for the whole order (at least 3 characters).'],
            ]);
        }
    }
}
