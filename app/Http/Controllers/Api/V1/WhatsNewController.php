<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformWhatsNewNote;
use App\Services\Platform\PlatformWhatsNewService;
use Illuminate\Http\Request;

class WhatsNewController extends Controller
{
    public function __construct(protected PlatformWhatsNewService $service) {}

    /** Pending login-modal notes for the current user (workspace-scoped). */
    public function pending(Request $request)
    {
        $workspace = $request->query('workspace');
        $workspace = is_string($workspace) ? $workspace : null;

        return response()->json([
            'data' => $this->service->pendingForUser($request->user(), $workspace),
        ]);
    }

    public function dismiss(Request $request, int $id)
    {
        $note = PlatformWhatsNewNote::query()->findOrFail($id);
        $this->service->dismissForUser($note, $request->user());

        return response()->json(['ok' => true]);
    }
}
