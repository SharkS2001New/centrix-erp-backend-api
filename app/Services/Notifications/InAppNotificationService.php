<?php

namespace App\Services\Notifications;

use App\Models\ActionRequest;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InAppNotificationService
{

    /** @param  array<string, mixed>  $data */
    public function createForUser(User $recipient, array $data): InAppNotification
    {
        $notification = InAppNotification::query()->create([
            'organization_id' => (int) ($data['organization_id'] ?? $recipient->organization_id),
            'user_id' => $recipient->id,
            'action_request_id' => $data['action_request_id'] ?? null,
            'type' => (string) ($data['type'] ?? 'info'),
            'severity' => (string) ($data['severity'] ?? 'default'),
            'title' => (string) $data['title'],
            'message' => (string) $data['message'],
            'action_url' => $data['action_url'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        app(InAppNotificationMailDelivery::class)->deliver($notification, $recipient);

        return $notification;
    }

    public function unreadCount(User $user): int
    {
        return InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->where('is_read', false)
            ->whereNull('resolved_at')
            ->count();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listRecent(User $user, int $limit = 20): Collection
    {
        return InAppNotification::query()
            ->with(['actionRequest.requester', 'creator'])
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('created_at')
            ->limit(min($limit, 50))
            ->get()
            ->map(fn (InAppNotification $notification) => $this->format($notification, $user));
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = InAppNotification::query()
            ->with(['actionRequest.requester', 'creator'])
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id);

        $bucket = (string) ($filters['bucket'] ?? '');
        if ($bucket === 'pending_approvals') {
            $query->where('type', 'approval')
                ->whereNull('resolved_at')
                ->whereHas('actionRequest', fn ($q) => $q->where('status', 'pending'));
        } elseif ($bucket === 'unread') {
            $query->where('is_read', false)->whereNull('resolved_at');
        } elseif ($bucket === 'read') {
            $query->where('is_read', true);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(fn (InAppNotification $notification) => $this->format($notification, $user));
    }

    public function markRead(InAppNotification $notification, User $user): InAppNotification
    {
        abort_if((int) $notification->user_id !== (int) $user->id, 404);

        if (! $notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return $notification->fresh(['actionRequest.requester', 'creator']);
    }

    public function markAllRead(User $user): int
    {
        return InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function resolveForActionRequest(ActionRequest $request): void
    {
        InAppNotification::query()
            ->where('action_request_id', $request->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    /** @return array<string, mixed> */
    public function format(InAppNotification $notification, User $viewer): array
    {
        $request = $notification->actionRequest;
        $requester = $request?->requester ?? $notification->creator;

        $payload = [
            'id' => (int) $notification->id,
            'type' => $notification->type,
            'severity' => $notification->severity,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'is_read' => (bool) $notification->is_read,
            'resolved_at' => $notification->resolved_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'created_at_human' => $notification->created_at?->diffForHumans(),
            'requester' => $requester ? [
                'id' => (int) $requester->id,
                'full_name' => $requester->full_name,
                'username' => $requester->username,
            ] : null,
        ];

        if ($request !== null) {
            $payload['action_request'] = [
                'id' => (int) $request->id,
                'type' => $request->type,
                'status' => $request->status,
                'module' => $request->module,
                'reference_type' => $request->reference_type,
                'reference_id' => (int) $request->reference_id,
                'reason' => $request->reason,
                'can_approve' => app(ActionRequestService::class)->canApprove($viewer, $request),
            ];
        }

        return $payload;
    }
}
