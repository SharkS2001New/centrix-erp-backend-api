<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Auth\UserPermissionService;
use Illuminate\Support\Collection;

class AdminNotificationService
{
    public function __construct(
        protected InAppNotificationService $notifications,
        protected UserPermissionService $permissions,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function notifyPermission(User $actor, string $permission, array $data): int
    {
        $recipients = $this->permissions
            ->usersWithPermission((int) $actor->organization_id, $permission)
            ->filter(fn (User $user) => (int) $user->id !== (int) $actor->id)
            ->values();

        if ($recipients->isEmpty()) {
            $recipients = $this->adminRecipients($actor);
        }

        return $this->notifyMany($actor, $recipients, $data);
    }

    /** @param  array<string, mixed>  $data */
    public function notifyAdmins(User $actor, array $data): int
    {
        $recipients = $this->adminRecipients($actor);

        return $this->notifyMany($actor, $recipients, $data);
    }

    /** @param  array<string, mixed>  $data */
    public function notifySuperAdmins(User $actor, array $data): int
    {
        $recipients = User::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->get();

        $count = 0;
        foreach ($recipients as $recipient) {
            $this->notifications->createForUser($recipient, [
                'organization_id' => (int) $recipient->organization_id,
                'type' => $data['type'] ?? 'info',
                'severity' => $data['severity'] ?? 'default',
                'title' => (string) $data['title'],
                'message' => (string) $data['message'],
                'action_url' => $data['action_url'] ?? null,
                'created_by' => $actor->id,
            ]);
            $count++;
        }

        return $count;
    }

    /** @return Collection<int, User> */
    protected function adminRecipients(User $actor): Collection
    {
        return User::query()
            ->where('organization_id', $actor->organization_id)
            ->where('is_active', true)
            ->where('is_admin', true)
            ->whereKeyNot($actor->id)
            ->get();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array<string, mixed>  $data
     */
    protected function notifyMany(User $actor, Collection $recipients, array $data): int
    {
        $count = 0;
        foreach ($recipients as $recipient) {
            $this->notifications->createForUser($recipient, [
                'organization_id' => $actor->organization_id,
                'type' => $data['type'] ?? 'info',
                'severity' => $data['severity'] ?? 'default',
                'title' => (string) $data['title'],
                'message' => (string) $data['message'],
                'action_url' => $data['action_url'] ?? null,
                'created_by' => $actor->id,
            ]);
            $count++;
        }

        return $count;
    }
}
