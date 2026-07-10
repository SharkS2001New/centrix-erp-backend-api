<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LpoAttachment;
use App\Models\LpoMst;
use Illuminate\Http\Request;
use App\Support\StoredPublicFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LpoAttachmentController extends Controller
{
    public function index(Request $request)
    {
        $query = LpoAttachment::query()->orderByDesc('id');

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
            'file' => 'required|file|max:10240|mimes:pdf,jpeg,jpg,png,webp,doc,docx,xls,xlsx',
        ]);

        LpoMst::findOrFail((int) $data['lpo_no']);

        $file = $request->file('file');
        $orgId = $request->user()?->organization_id;
        $path = $file->store(
            \App\Support\OrganizationPublicStorage::path($orgId, 'lpo', (string) ((int) $data['lpo_no']), 'attachments'),
            'public',
        );

        $attachment = LpoAttachment::create([
            'lpo_no' => (int) $data['lpo_no'],
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
            'created_at' => now(),
        ]);

        return response()->json($attachment, 201);
    }

    public function show(string $attachment)
    {
        return response()->json(LpoAttachment::findOrFail((int) $attachment));
    }

    public function destroy(string $attachment)
    {
        $row = LpoAttachment::findOrFail((int) $attachment);
        if ($row->file_path) {
            Storage::disk('public')->delete($row->file_path);
        }
        $row->delete();

        return response()->json(null, 204);
    }

    public function file(string $attachment)
    {
        $row = LpoAttachment::findOrFail((int) $attachment);

        if (! StoredPublicFile::exists($row->file_path)) {
            abort(Response::HTTP_NOT_FOUND, 'Attachment file not found.');
        }

        return StoredPublicFile::response($row->file_path, $row->mime_type ?: 'application/octet-stream', [
            'Content-Disposition' => 'inline; filename="'.$row->file_name.'"',
        ]);
    }
}
