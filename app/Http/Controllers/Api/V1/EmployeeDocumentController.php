<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use App\Support\StoredPublicFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EmployeeDocumentController extends Controller
{
    public function index(string $employee)
    {
        Employee::findOrFail((int) $employee);

        return response()->json(
            EmployeeDocument::where('employee_id', (int) $employee)->orderByDesc('id')->get(),
        );
    }

    public function store(Request $request, string $employee)
    {
        $emp = Employee::findOrFail((int) $employee);

        $data = $request->validate([
            'document_type' => 'nullable|in:contract,national_id,passport,kra_pin,offer_letter,certificate,other',
            'title' => 'required|string|max:200',
            'notes' => 'nullable|string|max:500',
            'file' => 'required|file|max:10240|mimes:pdf,jpeg,jpg,png,webp,doc,docx',
        ]);

        $file = $request->file('file');
        $path = $file->store(
            \App\Support\OrganizationPublicStorage::path($emp->organization_id, 'employees', (string) $emp->id, 'documents'),
            'public',
        );

        $doc = EmployeeDocument::create([
            'employee_id' => $emp->id,
            'document_type' => $data['document_type'] ?? 'other',
            'title' => $data['title'],
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($doc, 201);
    }

    public function show(string $employee, string $document)
    {
        return response()->json($this->findDoc((int) $employee, (int) $document));
    }

    public function update(Request $request, string $employee, string $document)
    {
        $doc = $this->findDoc((int) $employee, (int) $document);
        $data = $request->validate([
            'document_type' => 'nullable|in:contract,national_id,passport,kra_pin,offer_letter,certificate,other',
            'title' => 'sometimes|string|max:200',
            'notes' => 'nullable|string|max:500',
        ]);
        $doc->update($data);

        return response()->json($doc->fresh());
    }

    public function destroy(string $employee, string $document)
    {
        $doc = $this->findDoc((int) $employee, (int) $document);
        if ($doc->file_path) {
            Storage::disk('public')->delete($doc->file_path);
        }
        $doc->delete();

        return response()->json(null, 204);
    }

    public function file(string $employee, string $document)
    {
        $doc = $this->findDoc((int) $employee, (int) $document);

        if (! StoredPublicFile::exists($doc->file_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return StoredPublicFile::response($doc->file_path, $doc->mime_type ?: 'application/octet-stream', [
            'Content-Disposition' => 'inline; filename="'.$doc->file_name.'"',
        ]);
    }

    protected function findDoc(int $employeeId, int $documentId): EmployeeDocument
    {
        return EmployeeDocument::where('employee_id', $employeeId)->findOrFail($documentId);
    }
}
