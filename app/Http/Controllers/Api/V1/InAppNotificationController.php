<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionRequest;
use App\Models\InAppNotification;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\InAppNotificationService;
use Illuminate\Http\Request;

class InAppNotificationController extends Controller
{
    public function __construct(
        protected InAppNotificationService $notifications,
        protected ActionRequestService $actionRequests,
    ) {}

    public function unreadCount(Request $request)
    {
        return response()->json([
            'count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    public function index(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 20), 1), 50);

        return response()->json([
            'data' => $this->notifications->listRecent($request->user(), $limit)->values(),
        ]);
    }

    public function all(Request $request)
    {
        $filters = $request->only(['bucket', 'per_page']);
        $page = $this->notifications->paginate($request->user(), $filters);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = InAppNotification::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail((int) $id);

        $updated = $this->notifications->markRead($notification, $request->user());

        return response()->json([
            'data' => $this->notifications->format($updated, $request->user()),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $count = $this->notifications->markAllRead($request->user());

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated' => $count,
        ]);
    }

    public function approveActionRequest(Request $request, string $id)
    {
        $actionRequest = ActionRequest::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail((int) $id);

        $resolved = $this->actionRequests->approve($actionRequest, $request->user());

        return response()->json([
            'data' => [
                'id' => (int) $resolved->id,
                'status' => $resolved->status,
                'resolved_at' => $resolved->resolved_at?->toIso8601String(),
            ],
        ]);
    }

    public function rejectActionRequest(Request $request, string $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $actionRequest = ActionRequest::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail((int) $id);

        $resolved = $this->actionRequests->reject($actionRequest, $request->user(), $data['reason']);

        return response()->json([
            'data' => [
                'id' => (int) $resolved->id,
                'status' => $resolved->status,
                'resolved_at' => $resolved->resolved_at?->toIso8601String(),
            ],
        ]);
    }
}
