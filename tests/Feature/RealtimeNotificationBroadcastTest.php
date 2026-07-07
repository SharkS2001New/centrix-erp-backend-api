<?php

namespace Tests\Feature;

use App\Events\InAppNotificationCreated;
use App\Models\User;
use App\Services\Notifications\InAppNotificationService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RealtimeNotificationBroadcastTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_in_app_notification_broadcasts_to_recipient_channel_when_enabled(): void
    {
        config(['broadcasting.default' => 'reverb']);

        Broadcast::fake();

        $admin = User::where('username', 'admin')->firstOrFail();
        $recipientId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'realtime_notify_test',
            'email' => 'realtime_notify_test@example.test',
            'password' => $admin->password,
            'full_name' => 'Realtime Notify Test',
            'is_admin' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $recipient = User::query()->findOrFail($recipientId);

        app(InAppNotificationService::class)->createForUser($recipient, [
            'organization_id' => $recipient->organization_id,
            'type' => 'approval',
            'severity' => 'warning',
            'title' => 'Approval required',
            'message' => 'Please review this request.',
            'created_by' => $admin->id,
        ]);

        Broadcast::assertBroadcasted(InAppNotificationCreated::class, function (InAppNotificationCreated $event) use ($recipient) {
            return (int) $event->notification->user_id === (int) $recipient->id
                && $event->unreadCount >= 1
                && $event->broadcastAs() === 'notification.created';
        });
    }

    public function test_in_app_notification_does_not_broadcast_when_disabled(): void
    {
        config(['broadcasting.default' => 'null']);

        Broadcast::fake();

        $admin = User::where('username', 'admin')->firstOrFail();

        app(InAppNotificationService::class)->createForUser($admin, [
            'organization_id' => $admin->organization_id,
            'type' => 'info',
            'title' => 'Hello',
            'message' => 'Test',
        ]);

        Broadcast::assertNothingBroadcasted();
    }
}
