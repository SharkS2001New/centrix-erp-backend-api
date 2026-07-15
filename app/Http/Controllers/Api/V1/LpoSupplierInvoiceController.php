<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LpoMst;
use App\Models\LpoSupplierInvoice;
use App\Services\Auth\UserAccessService;
use App\Support\StoredPublicFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LpoSupplierInvoiceController extends Controller
{
    protected function access(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    protected function scopedQuery(Request $request)
    {
        $query = LpoSupplierInvoice::query()->orderByDesc('id');
        $user = $request->user();
        if (! $user) {
            return $query;
        }

        $orgId = $this->access()->organizationId($user, $request);
        if ($orgId) {
            $query->whereHas('lpo', fn ($lpo) => $lpo->where('organization_id', $orgId));
        }

        return $query;
    }

    protected function findScoped(Request $request, string $id): LpoSupplierInvoice
    {
        return $this->scopedQuery($request)->whereKey((int) $id)->firstOrFail();
    }

    protected function assertLpoInOrganization(Request $request, int $lpoNo): LpoMst
    {
        $query = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo);
        $user = $request->user();
        if ($user) {
            $orgId = $this->access()->organizationId($user, $request);
            if ($orgId) {
                $query->where('organization_id', $orgId);
            }
        }

        return $query->firstOrFail();
    }

    protected function storeUploadedFile(Request $request, int $lpoNo): array
    {
        $file = $request->file('file');
        $orgId = $request->user()?->organization_id;
        $path = $file->store(
            \App\Support\OrganizationPublicStorage::path($orgId, 'lpo', (string) $lpoNo, 'supplier-invoices'),
            'public',
        );

        return [
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
        ];
    }

    protected function deleteStoredFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    public function index(Request $request)
    {
        $query = $this->scopedQuery($request);
        if ($request->filled('filter.lpo_no')) {
            $query->where('lpo_no', (int) $request->input('filter.lpo_no'));
        }

        return response()->json([
            'data' => $query->limit(200)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lpo_no' => 'required|integer',
            'supplier_id' => 'required|integer',
            'supplier_invoice_number' => 'required|string|max:100',
            'invoice_date' => 'nullable|date',
            'invoice_amount' => 'nullable|numeric|min:0',
            'file' => 'required|file|max:10240|mimes:pdf,jpeg,jpg,png,webp',
        ]);

        $this->assertLpoInOrganization($request, (int) $data['lpo_no']);

        $invoice = LpoSupplierInvoice::create([
            'lpo_no' => (int) $data['lpo_no'],
            'supplier_id' => (int) $data['supplier_id'],
            'supplier_invoice_number' => trim($data['supplier_invoice_number']),
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_amount' => $data['invoice_amount'] ?? null,
            ...$this->storeUploadedFile($request, (int) $data['lpo_no']),
        ]);

        return response()->json($invoice, 201);
    }

    public function show(Request $request, string $lpo_supplier_invoice)
    {
        return response()->json($this->findScoped($request, $lpo_supplier_invoice));
    }

    public function update(Request $request, string $lpo_supplier_invoice)
    {
        $invoice = $this->findScoped($request, $lpo_supplier_invoice);
        $data = $request->validate([
            'supplier_invoice_number' => 'sometimes|required|string|max:100',
            'invoice_date' => 'nullable|date',
            'invoice_amount' => 'nullable|numeric|min:0',
        ]);

        if (array_key_exists('supplier_invoice_number', $data)) {
            $data['supplier_invoice_number'] = trim($data['supplier_invoice_number']);
        }

        $invoice->update($data);

        return response()->json($invoice->fresh());
    }

    public function destroy(Request $request, string $lpo_supplier_invoice)
    {
        $invoice = $this->findScoped($request, $lpo_supplier_invoice);
        $this->deleteStoredFile($invoice->file_path);
        $invoice->delete();

        return response()->json(null, 204);
    }

    public function uploadDocument(Request $request, string $lpo_supplier_invoice)
    {
        $invoice = $this->findScoped($request, $lpo_supplier_invoice);
        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpeg,jpg,png,webp',
        ]);

        $this->deleteStoredFile($invoice->file_path);
        $invoice->update($this->storeUploadedFile($request, (int) $invoice->lpo_no));

        return response()->json($invoice->fresh());
    }

    public function file(Request $request, string $lpo_supplier_invoice)
    {
        $invoice = $this->findScoped($request, $lpo_supplier_invoice);

        if (! $invoice->file_path || ! StoredPublicFile::exists($invoice->file_path)) {
            abort(Response::HTTP_NOT_FOUND, 'Supplier invoice document not found.');
        }

        return StoredPublicFile::response(
            $invoice->file_path,
            $invoice->mime_type ?: 'application/octet-stream',
            [
                'Content-Disposition' => 'inline; filename="'.($invoice->file_name ?: 'supplier-invoice').'"',
            ],
        );
    }
}
