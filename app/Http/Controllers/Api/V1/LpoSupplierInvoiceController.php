<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LpoMst;
use App\Models\LpoSupplierInvoice;
use App\Services\SupplierBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LpoSupplierInvoiceController extends BaseResourceController
{
    public function __construct(
        protected SupplierBalanceService $supplierBalances,
    ) {}

    protected function modelClass(): string
    {
        return LpoSupplierInvoice::class;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lpo_no' => 'required|integer',
            'supplier_id' => 'required|integer',
            'supplier_invoice_number' => 'required|string|max:100',
            'invoice_date' => 'nullable|date',
            'invoice_amount' => 'nullable|numeric|min:0',
            'file' => 'nullable|file|max:15360|mimes:pdf,jpeg,jpg,png,webp',
        ]);

        $fileMeta = $this->storeUploadedFile($request, (int) $data['lpo_no']);

        $model = LpoSupplierInvoice::create([
            ...$data,
            ...$fileMeta,
        ]);

        $this->applyInvoiceToLpo($model);
        $this->supplierBalances->recalculate((int) $model->supplier_id);

        return response()->json($this->mapInvoice($model), 201);
    }

    public function update(Request $request, string $id)
    {
        $model = LpoSupplierInvoice::findOrFail($id);
        $data = $request->validate([
            'supplier_invoice_number' => 'sometimes|string|max:100',
            'invoice_date' => 'nullable|date',
            'invoice_amount' => 'nullable|numeric|min:0',
            'file' => 'nullable|file|max:15360|mimes:pdf,jpeg,jpg,png,webp',
        ]);

        if ($request->hasFile('file')) {
            $this->deleteStoredFile($model);
            $data = array_merge($data, $this->storeUploadedFile($request, (int) $model->lpo_no));
        }

        $model->update($data);
        $model = $model->fresh();
        $this->applyInvoiceToLpo($model);
        $this->supplierBalances->recalculate((int) $model->supplier_id);

        return response()->json($this->mapInvoice($model));
    }

    public function uploadDocument(Request $request, string $id)
    {
        $model = LpoSupplierInvoice::findOrFail($id);
        $request->validate([
            'file' => 'required|file|max:15360|mimes:pdf,jpeg,jpg,png,webp',
        ]);

        $this->deleteStoredFile($model);
        $model->update($this->storeUploadedFile($request, (int) $model->lpo_no));

        return response()->json($this->mapInvoice($model->fresh()));
    }

    public function file(string $id)
    {
        $model = LpoSupplierInvoice::findOrFail($id);

        if (! $model->file_path || ! Storage::disk('public')->exists($model->file_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $absolute = Storage::disk('public')->path($model->file_path);

        return response()->file($absolute, [
            'Content-Type' => $model->mime_type ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($model->file_name ?: 'supplier-invoice').'"',
        ]);
    }

    public function destroy(string $id)
    {
        $model = LpoSupplierInvoice::findOrFail($id);
        $supplierId = (int) $model->supplier_id;
        $this->deleteStoredFile($model);
        $model->delete();
        $this->supplierBalances->recalculate($supplierId);

        return response()->json(null, 204);
    }

    protected function storeUploadedFile(Request $request, int $lpoNo): array
    {
        if (! $request->hasFile('file')) {
            return [
                'file_path' => null,
                'file_name' => null,
                'mime_type' => null,
                'file_size' => null,
            ];
        }

        $file = $request->file('file');
        $path = $file->store("lpo/{$lpoNo}/supplier-invoices", 'public');

        return [
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ];
    }

    protected function deleteStoredFile(LpoSupplierInvoice $invoice): void
    {
        if ($invoice->file_path) {
            Storage::disk('public')->delete($invoice->file_path);
        }
    }

    protected function mapInvoice(LpoSupplierInvoice $inv): array
    {
        return [
            'id' => $inv->id,
            'lpo_no' => (int) $inv->lpo_no,
            'supplier_id' => (int) $inv->supplier_id,
            'supplier_invoice_number' => $inv->supplier_invoice_number,
            'invoice_date' => $inv->invoice_date,
            'invoice_amount' => $inv->invoice_amount !== null ? (float) $inv->invoice_amount : null,
            'file_name' => $inv->file_name,
            'mime_type' => $inv->mime_type,
            'file_size' => $inv->file_size,
            'has_document' => (bool) $inv->file_path,
            'document_url' => $inv->file_path ? url('/storage/'.$inv->file_path) : null,
        ];
    }

    protected function applyInvoiceToLpo(LpoSupplierInvoice $invoice): void
    {
        if (! $invoice->lpo_no || ! $invoice->supplier_invoice_number) {
            return;
        }

        LpoMst::query()
            ->where('lpo_no', $invoice->lpo_no)
            ->whereNull('deleted_at')
            ->update([
                'supplier_invoice_no' => $invoice->supplier_invoice_number,
            ]);
    }
}
