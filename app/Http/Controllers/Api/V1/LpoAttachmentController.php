<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LpoAttachment;
use App\Models\LpoMst;
use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;
use App\Support\StoredPublicFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LpoAttachmentController extends Controller
{
    protected function access(): UserAccessService
    {
        return app(UserAccessService::class);
    }

    protected function scopedLpoQuery(Request $request, int $lpoNo)
    {
        $query = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $lpoNo);
        $user = $request->user();
        if ($user) {
            $this->access()->scopeOrganization($query, $user, 'organization_id', $request);
        }

        return $query;
    }

    protected function findScopedAttachment(Request $request, int $attachmentId): LpoAttachment
    {
        $query = LpoAttachment::query()->whereKey($attachmentId);
        $user = $request->user();
        if ($user) {
            $orgId = $this->access()->organizationId($user, $request);
            if ($orgId) {
                $query->whereHas('lpo', fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'));
            }
        }

        return $query->firstOrFail();
    }

    public function index(Request $request)
    {
        $query = LpoAttachment::query()->orderByDesc('id');
        $user = $request->user();
        if ($user) {
            $orgId = $this->access()->organizationId($user, $request);
            if ($orgId) {
                $query->whereHas('lpo', fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at'));
            }
        }

        if ($request->filled('filter.lpo_no')) {
            $lpoNo = (int) $request->input('filter.lpo_no');
            $this->scopedLpoQuery($request, $lpoNo)->firstOrFail();
            $query->where('lpo_no', $lpoNo);
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

        $lpo = $this->scopedLpoQuery($request, (int) $data['lpo_no'])->firstOrFail();
        $orgId = (int) ($lpo->organization_id ?? $this->access()->organizationId($request->user(), $request) ?? 0);

        $file = $request->file('file');
        $path = $file->store(
            \App\Support\OrganizationPublicStorage::path($orgId ?: null, 'lpo', (string) ((int) $data['lpo_no']), 'attachments'),
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

    public function show(Request $request, string $attachment)
    {
        return response()->json($this->findScopedAttachment($request, (int) $attachment));
    }

    public function destroy(Request $request, string $attachment)
    {
        $row = $this->findScopedAttachment($request, (int) $attachment);
        if ($row->file_path) {
            Storage::disk('public')->delete($row->file_path);
        }
        $row->delete();

        return response()->json(null, 204);
    }

    public function file(Request $request, string $attachment)
    {
        $row = $this->findScopedAttachment($request, (int) $attachment);

        if (! StoredPublicFile::exists($row->file_path)) {
            abort(Response::HTTP_NOT_FOUND, 'Attachment file not found.');
        }

        return StoredPublicFile::response($row->file_path, $row->mime_type ?: 'application/octet-stream', [
            'Content-Disposition' => 'inline; filename="'.$row->file_name.'"',
        ]);
    }
}
