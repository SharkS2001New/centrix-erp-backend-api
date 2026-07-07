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
        $user = $request->user();

        return response()->json([
            'count' => $this->notifications->unreadCount($user),
            'pending_approvals_count' => $this->notifications->pendingApprovalsCount($user),
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

    public function dismiss(Request $request, string $id)
    {
        $notification = InAppNotification::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail((int) $id);

        try {
            $updated = $this->notifications->dismiss($notification, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->notifications->format($updated, $request->user()),
        ]);
    }

    public function clearAll(Request $request)
    {
        $count = $this->notifications->clearAll($request->user());

        return response()->json([
            'message' => 'Notifications cleared.',
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
        $actionRequest = ActionRequest::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail((int) $id);

        $rules = [
            'reason' => 'required|string|min:3',
        ];
        if ($actionRequest->type === 'discount') {
            $rules['discount_guidance'] = 'required|in:remove_discount,advised_amount';
            $rules['advised_discount_lines'] = 'required_if:discount_guidance,advised_amount|nullable|array|min:1';
            $rules['advised_discount_lines.*.product_code'] = 'required_with:advised_discount_lines|string|max:50';
            $rules['advised_discount_lines.*.advised_discount'] = 'required_with:advised_discount_lines|numeric|min:0';
            $rules['advised_discount_amount'] = 'nullable|numeric|min:0';
        }

        $data = $request->validate($rules);

        $options = [];
        if ($actionRequest->type === 'discount') {
            $advisedLines = ($data['discount_guidance'] ?? '') === 'advised_amount'
                ? collect($data['advised_discount_lines'] ?? [])
                    ->map(fn ($line) => [
                        'product_code' => (string) ($line['product_code'] ?? ''),
                        'advised_discount' => round((float) ($line['advised_discount'] ?? 0), 2),
                    ])
                    ->values()
                    ->all()
                : [];

            $options = [
                'discount_guidance' => (string) $data['discount_guidance'],
                'advised_discount_lines' => $advisedLines,
                'advised_discount_amount' => ($data['discount_guidance'] ?? '') === 'advised_amount'
                    ? round((float) ($data['advised_discount_amount'] ?? 0), 2)
                    : null,
            ];
        }

        $resolved = $this->actionRequests->reject($actionRequest, $request->user(), $data['reason'], $options);

        return response()->json([
            'data' => [
                'id' => (int) $resolved->id,
                'status' => $resolved->status,
                'resolved_at' => $resolved->resolved_at?->toIso8601String(),
            ],
        ]);
    }

    public function remindActionRequest(Request $request, string $id)
    {
        $actionRequest = ActionRequest::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail((int) $id);

        $resolved = $this->actionRequests->sendReminder($actionRequest, $request->user());

        return response()->json([
            'message' => 'Approval reminder sent.',
            'data' => [
                'id' => (int) $resolved->id,
                'status' => $resolved->status,
                'can_remind' => $this->actionRequests->canRemind($request->user(), $resolved),
            ],
        ]);
    }
}
