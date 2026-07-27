<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\SupplierCreditNoteService;
use Illuminate\Http\Request;

class SupplierCreditNoteController extends Controller
{
    public function __construct(
        protected SupplierCreditNoteService $service,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only([
            'supplier_id', 'status', 'from_date', 'to_date', 'per_page', 'page', 'branch_id', 'q',
        ]);

        $paginator = $this->service->listForUser($request->user(), $filters);
        $paginator->through(
            fn ($note) => $this->service->withActionFlags($note, $request->user()),
        );

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'credit_date' => 'nullable|date',
            'total_amount' => 'nullable|numeric|min:0.01',
            'reason' => 'required|string|min:3|max:500',
            'description' => 'nullable|string|max:5000',
            'supplier_invoice_no' => 'nullable|string|max:100',
            'lpo_no' => 'nullable|integer',
            'notes' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*.product_code' => 'nullable|string|max:200',
            'lines.*.product_name' => 'nullable|string|max:200',
            'lines.*.description' => 'nullable|string|max:1000',
            'lines.*.amount' => 'required_with:lines|numeric|min:0.01',
            'lines.*.line_no' => 'nullable|integer',
        ]);

        $note = $this->service->create($request->user(), $data);

        return response()->json(
            $this->service->withActionFlags($note, $request->user()),
            201,
        );
    }

    public function show(Request $request, string $id)
    {
        $note = $this->service->findForUser($request->user(), (int) $id);
        $note->load(['lines', 'supplier', 'createdByUser', 'approvedByUser', 'rejectedByUser']);

        return response()->json($this->service->withActionFlags($note, $request->user()));
    }

    public function approve(Request $request, string $id)
    {
        $note = $this->service->findForUser($request->user(), (int) $id);
        $approved = $this->service->approve($note, $request->user());

        return response()->json($this->service->withActionFlags($approved, $request->user()));
    }

    public function reject(Request $request, string $id)
    {
        $note = $this->service->findForUser($request->user(), (int) $id);

        $data = $request->validate([
            'reason' => 'nullable|string|min:3|max:500',
        ]);

        $rejected = $this->service->reject($note, $request->user(), $data['reason'] ?? null);

        return response()->json($this->service->withActionFlags($rejected, $request->user()));
    }

    public function destroy(Request $request, string $id)
    {
        $note = $this->service->findForUser($request->user(), (int) $id);
        $this->service->deleteNote($note, $request->user());

        return response()->json(['deleted' => true]);
    }
}
