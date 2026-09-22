<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LpoMst;
use App\Models\LpoSupplierInvoice;
use App\Services\Auth\UserAccessService;
use App\Support\StoredPublicFile;
use App\Support\UploadedImageProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        $directory = \App\Support\OrganizationPublicStorage::path($orgId, 'lpo', (string) $lpoNo, 'supplier-invoices');
        $processor = UploadedImageProcessor::forDocument();
        if ($processor->isProcessableImage($file)) {
            $stored = $processor->storePublicImage($file, $directory);

            return [
                'file_path' => $stored['path'],
                'file_name' => $stored['file_name'],
                'mime_type' => $stored['mime_type'],
                'file_size' => $stored['size'],
                'uploaded_by' => $request->user()?->id,
            ];
        }

        $path = $file->store($directory, 'public');

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
        $query = $this->scopedQuery($request)
            ->with([
                'supplier:id,supplier_name,supplier_code',
                'lpo:lpo_no,organization_id,lpo_seq,reference_number,created_at',
            ]);

        if ($lpoNo = $request->input('lpo_no') ?? $request->input('filter.lpo_no')) {
            $query->where('lpo_no', (int) $lpoNo);
        }
        if ($supplierId = $request->input('supplier_id') ?? $request->input('filter.supplier_id')) {
            $query->where('supplier_id', (int) $supplierId);
        }
        if ($from = $request->input('date_from') ?? $request->input('filter.date_from')) {
            $query->whereRaw('DATE(COALESCE(invoice_date, created_at)) >= ?', [$from]);
        }
        if ($to = $request->input('date_to') ?? $request->input('filter.date_to')) {
            $query->whereRaw('DATE(COALESCE(invoice_date, created_at)) <= ?', [$to]);
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('supplier_invoice_number', 'like', "%{$q}%")
                    ->orWhere('file_name', 'like', "%{$q}%")
                    ->orWhereHas(
                        'supplier',
                        fn ($supplier) => $supplier->where('supplier_name', 'like', "%{$q}%")
                            ->orWhere('supplier_code', 'like', "%{$q}%"),
                    )
                    ->orWhereHas(
                        'lpo',
                        fn ($lpo) => $lpo->where('reference_number', 'like', "%{$q}%")
                            ->orWhere('lpo_seq', 'like', "%{$q}%")
                            ->orWhere('lpo_no', 'like', "%{$q}%"),
                    );
            });
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $paginator = $query->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (LpoSupplierInvoice $invoice) => $this->mapListRow($invoice)),
        );

        return response()->json($paginator);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapListRow(LpoSupplierInvoice $invoice): array
    {
        $lpo = $invoice->lpo;
        $supplier = $invoice->supplier;

        return [
            'id' => (int) $invoice->id,
            'lpo_no' => (int) $invoice->lpo_no,
            'lpo_seq' => $lpo?->lpo_seq !== null ? (int) $lpo->lpo_seq : null,
            'reference_number' => $lpo?->reference_number,
            'po_number' => $lpo?->reference_number
                ?: ($lpo?->lpo_seq !== null ? 'LPO-'.$lpo->lpo_seq : null),
            'supplier_id' => (int) $invoice->supplier_id,
            'supplier_name' => $supplier?->supplier_name,
            'supplier_code' => $supplier?->supplier_code,
            'supplier_invoice_number' => (string) ($invoice->supplier_invoice_number ?? ''),
            'invoice_date' => $invoice->invoice_date,
            'invoice_amount' => $invoice->invoice_amount !== null
                ? round((float) $invoice->invoice_amount, 2)
                : null,
            'has_document' => filled($invoice->file_path),
            'file_name' => $invoice->file_name,
            'mime_type' => $invoice->mime_type,
            'file_size' => $invoice->file_size !== null ? (int) $invoice->file_size : null,
            'created_at' => $invoice->created_at,
        ];
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
        $number = trim($data['supplier_invoice_number']);
        $this->assertInvoiceNumberUniqueToOneLpo(
            supplierId: (int) $data['supplier_id'],
            invoiceNumber: $number,
            lpoNo: (int) $data['lpo_no'],
        );

        $invoice = LpoSupplierInvoice::create([
            'lpo_no' => (int) $data['lpo_no'],
            'supplier_id' => (int) $data['supplier_id'],
            'supplier_invoice_number' => $number,
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
            $this->assertInvoiceNumberUniqueToOneLpo(
                supplierId: (int) $invoice->supplier_id,
                invoiceNumber: $data['supplier_invoice_number'],
                lpoNo: (int) $invoice->lpo_no,
                ignoreInvoiceId: (int) $invoice->id,
            );
        }

        $invoice->update($data);

        return response()->json($invoice->fresh());
    }

    /**
     * A supplier invoice number may only exist on one LPO for that supplier
     * (so accounts can search the number and land on a single purchase order).
     */
    protected function assertInvoiceNumberUniqueToOneLpo(
        int $supplierId,
        string $invoiceNumber,
        int $lpoNo,
        ?int $ignoreInvoiceId = null,
    ): void {
        $normalized = mb_strtolower(trim($invoiceNumber));
        if ($normalized === '') {
            return;
        }

        $conflict = LpoSupplierInvoice::query()
            ->where('supplier_id', $supplierId)
            ->when($ignoreInvoiceId, fn ($q) => $q->where('id', '!=', $ignoreInvoiceId))
            ->whereRaw('LOWER(TRIM(supplier_invoice_number)) = ?', [$normalized])
            ->orderByDesc('id')
            ->first(['id', 'lpo_no', 'supplier_invoice_number']);

        if ($conflict) {
            $message = (int) $conflict->lpo_no !== $lpoNo
                ? "Invoice number {$invoiceNumber} is already linked to another LPO. Each invoice number can only belong to one LPO."
                : "Invoice number {$invoiceNumber} is already attached on this LPO.";

            throw ValidationException::withMessages([
                'supplier_invoice_number' => [$message],
            ]);
        }

        // Same number on a different LPO via the LPO header field.
        $headerConflict = LpoMst::query()
            ->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')
            ->where('lpo_no', '!=', $lpoNo)
            ->whereRaw('LOWER(TRIM(supplier_invoice_no)) = ?', [$normalized])
            ->orderByDesc('lpo_no')
            ->first(['lpo_no', 'supplier_invoice_no']);

        if ($headerConflict) {
            throw ValidationException::withMessages([
                'supplier_invoice_number' => [
                    "Invoice number {$invoiceNumber} is already used on another LPO. Each invoice number can only belong to one LPO.",
                ],
            ]);
        }
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
