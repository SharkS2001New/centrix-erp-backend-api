<?php

namespace App\Services\Notifications;

use App\Events\InAppNotificationCreated;
use App\Models\InAppNotification;
use App\Models\User;

class RealtimeNotificationBroadcaster
{
    public function __construct(
        protected InAppNotificationService $notifications,
    ) {}

    public function enabled(): bool
    {
        $connection = (string) config('broadcasting.default', 'null');

        return $connection !== '' && $connection !== 'null';
    }

    public function notifyCreated(InAppNotification $notification, User $recipient): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            broadcast(new InAppNotificationCreated(
                $notification,
                $this->notifications->unreadCount($recipient),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
