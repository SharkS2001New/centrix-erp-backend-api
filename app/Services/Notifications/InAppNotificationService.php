<?php

namespace App\Services\Notifications;

use App\Models\ActionRequest;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Mobile\FcmPushNotificationService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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

        if (in_array($data['type'] ?? '', ['approval', 'approval_outcome'], true)) {
            app(FcmPushNotificationService::class)->notifyInAppNotification($notification, $recipient);
        }

        app(RealtimeNotificationBroadcaster::class)->notifyCreated($notification, $recipient);

        return $notification;
    }

    public function unreadCount(User $user, ?string $workspace = null): int
    {
        $query = $this->visibleQuery($user)
            ->where('is_read', false)
            ->whereNull('resolved_at');
        $this->applyWorkspaceFilter($query, $workspace);

        return $query->count();
    }

    /** Highest visible notification id (any read state) for client watermark polls. */
    public function latestVisibleId(User $user, ?string $workspace = null): int
    {
        $query = $this->activeQuery($user)->orderByDesc('id');
        $this->applyWorkspaceFilter($query, $workspace);

        return (int) ($query->value('id') ?? 0);
    }

    public function pendingApprovalsCount(User $user, ?string $workspace = null): int
    {
        $query = $this->visibleQuery($user)
            ->where('type', 'approval')
            ->whereNull('resolved_at')
            ->whereHas('actionRequest', fn ($q) => $q->where('status', 'pending'));
        $this->applyWorkspaceFilter($query, $workspace);

        return $query->count();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listRecent(User $user, int $limit = 20, ?string $workspace = null): Collection
    {
        $query = $this->activeQuery($user)
            ->with(['actionRequest.requester', 'creator'])
            ->orderByDesc('created_at')
            ->limit(min($limit, 50));
        $this->applyWorkspaceFilter($query, $workspace);

        return $this->formatMany($query->get(), $user);
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->visibleQuery($user)
            ->with(['actionRequest.requester', 'creator']);
        $this->applyWorkspaceFilter($query, $filters['workspace'] ?? null);

        $bucket = (string) ($filters['bucket'] ?? '');
        if ($bucket === 'pending_approvals') {
            $query->where('type', 'approval')
                ->whereNull('resolved_at')
                ->whereHas('actionRequest', fn ($q) => $q->where('status', 'pending'));
        } elseif ($bucket === 'unread') {
            $query->where('is_read', false)->whereNull('resolved_at');
        } elseif ($bucket === 'read') {
            $query->where('is_read', true);
        } elseif ($bucket === '') {
            $query->whereNull('resolved_at');
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);
        $paginator->setCollection($this->formatMany($paginator->getCollection(), $user));

        return $paginator;
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

    public function markAllRead(User $user, ?string $workspace = null): int
    {
        $query = $this->visibleQuery($user)
            ->where('is_read', false);
        $this->applyWorkspaceFilter($query, $workspace);

        return $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function dismiss(InAppNotification $notification, User $user): InAppNotification
    {
        abort_if((int) $notification->user_id !== (int) $user->id, 404);

        if ($this->isPendingApproval($notification)) {
            throw new InvalidArgumentException('Pending approval notifications cannot be cleared until resolved.');
        }

        if ($notification->dismissed_at === null) {
            $notification->update([
                'dismissed_at' => now(),
                'is_read' => true,
                'read_at' => $notification->read_at ?? now(),
            ]);
        }

        return $notification->fresh(['actionRequest.requester', 'creator']);
    }

    public function clearAll(User $user, ?string $workspace = null): int
    {
        $query = $this->visibleQuery($user)
            ->where(function ($query) {
                $query->where('type', '!=', 'approval')
                    ->orWhereDoesntHave('actionRequest', fn ($q) => $q->where('status', 'pending'));
            });
        $this->applyWorkspaceFilter($query, $workspace);

        return $query->update([
            'dismissed_at' => now(),
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function isPendingApproval(InAppNotification $notification): bool
    {
        if ($notification->type !== 'approval') {
            return false;
        }

        $request = $notification->relationLoaded('actionRequest')
            ? $notification->actionRequest
            : $notification->actionRequest()->first();

        return $request !== null && $request->status === 'pending';
    }

    protected function visibleQuery(User $user)
    {
        return InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->whereNull('dismissed_at');
    }

    public function resolveForActionRequest(ActionRequest $request): void
    {
        InAppNotification::query()
            ->where('action_request_id', $request->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'dismissed_at' => now(),
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    protected function activeQuery(User $user)
    {
        return $this->visibleQuery($user)
            ->whereNull('resolved_at')
            ->where(function ($query) {
                $query->where('is_read', false)
                    ->orWhere(function ($pendingApproval) {
                        $pendingApproval->where('type', 'approval')
                            ->whereHas('actionRequest', fn ($actionRequest) => $actionRequest->where('status', 'pending'));
                    });
            });
    }

    /** @return array<string, mixed> */
    public function format(InAppNotification $notification, User $viewer): array
    {
        return $this->formatMany(collect([$notification]), $viewer)->first()
            ?? $this->formatOne($notification, $viewer, []);
    }

    /**
     * Format a page of notifications without per-row Sale::find / repeated permission scans.
     *
     * @param  Collection<int, InAppNotification>  $notifications
     * @return Collection<int, array<string, mixed>>
     */
    public function formatMany(Collection $notifications, User $viewer): Collection
    {
        $actions = app(ActionRequestService::class);
        // Cache permission checks per request type (only for pending samples).
        $canApproveByType = [];

        foreach ($notifications as $notification) {
            $request = $notification->actionRequest;
            if (! $request || ! $request->isPending()) {
                continue;
            }
            $type = (string) $request->type;
            if (! array_key_exists($type, $canApproveByType)) {
                $canApproveByType[$type] = $actions->canApprove($viewer, $request);
            }
        }

        return $notifications->map(
            fn (InAppNotification $notification) => $this->formatOne(
                $notification,
                $viewer,
                $canApproveByType,
            ),
        )->values();
    }

    /**
     * @param  array<string, bool>  $canApproveByType
     * @return array<string, mixed>
     */
    protected function formatOne(
        InAppNotification $notification,
        User $viewer,
        array $canApproveByType = [],
    ): array {
        $request = $notification->actionRequest;
        $requester = $request?->requester ?? $notification->creator;
        $actions = app(ActionRequestService::class);

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
            $type = (string) $request->type;
            $payload['action_request'] = [
                'id' => (int) $request->id,
                'type' => $request->type,
                'status' => $request->status,
                'module' => $request->module,
                'reference_type' => $request->reference_type,
                'reference_id' => (int) $request->reference_id,
                'reason' => $request->reason,
                'payload' => $request->payload,
                'can_approve' => $request->isPending()
                    ? ($canApproveByType[$type] ?? $actions->canApprove($viewer, $request))
                    : false,
                'can_remind' => $actions->canRemind($viewer, $request),
            ];

            if ($request->type === 'discount') {
                // Prefer stored payload lines — avoid Sale::find + line rebuild on every poll.
                $requestPayload = $request->payload ?? [];
                $lines = $requestPayload['lines'] ?? [];

                $payload['discount_approval'] = [
                    'scope' => $requestPayload['scope'] ?? null,
                    'discount_amount' => $requestPayload['discount_amount'] ?? null,
                    'discount_percent' => $requestPayload['discount_percent'] ?? null,
                    'order_discount' => $requestPayload['order_discount'] ?? null,
                    'lines' => $lines,
                    'advised_discount_applied' => ! empty($requestPayload['advised_discount_applied']),
                    'discount_revision_submitted' => ! empty($requestPayload['discount_revision_submitted']),
                ];
            }

            if ($request->type === 'lpo_approval') {
                $requestPayload = $request->payload ?? [];
                $payload['lpo_approval'] = [
                    'po_number' => $requestPayload['po_number'] ?? null,
                    'supplier_name' => $requestPayload['supplier_name'] ?? null,
                    'net_amount' => $requestPayload['net_amount'] ?? null,
                ];
            }
        }

        return $payload;
    }

    protected function applyWorkspaceFilter($query, ?string $workspace): void
    {
        app(NotificationWorkspaceFilter::class)->apply($query, $workspace);
    }
}
