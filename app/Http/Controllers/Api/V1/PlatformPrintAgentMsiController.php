<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PrintAgent\PrintAgentMsiService;
use App\Services\PrintAgent\PrintAgentMsiSettingsResolver;
use Illuminate\Http\Request;

class PlatformPrintAgentMsiController extends Controller
{
    public function __construct(
        protected PrintAgentMsiService $msi,
    ) {}

    /** GET /admin/print-agent-msi */
    public function show()
    {
        return response()->json(PrintAgentMsiSettingsResolver::describe());
    }

    /**
     * GET /print-agent-msi
     * Lightweight download info for till admins (not only platform super-admins).
     */
    public function downloadInfo()
    {
        $described = PrintAgentMsiSettingsResolver::describe();

        return response()->json([
            'available' => (bool) ($described['effective']['available'] ?? false),
            'public_url' => $described['effective']['public_url'] ?? '',
            'object_key' => $described['effective']['object_key'] ?? '',
        ]);
    }

    /** PUT /admin/print-agent-msi */
    public function update(Request $request)
    {
        $data = $request->validate([
            'object_key' => ['sometimes', 'string', 'max:255'],
            'public_url' => ['nullable', 'string', 'max:500'],
            'github_repo' => ['nullable', 'string', 'max:120'],
            'github_ref' => ['nullable', 'string', 'max:80'],
            'workflow_file' => ['nullable', 'string', 'max:120'],
            'github_token' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(PrintAgentMsiSettingsResolver::save($data));
    }

    /** POST /admin/print-agent-msi/build */
    public function build()
    {
        try {
            return response()->json($this->msi->queueBuild());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /admin/print-agent-msi/upload */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:512000'], // ~500 MB
        ]);

        try {
            return response()->json($this->msi->uploadMsi($request->file('file')));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
