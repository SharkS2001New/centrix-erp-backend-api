<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use App\Services\Notifications\RealtimeNotificationBroadcaster;
use App\Services\Platform\PlatformHealthProbe;
use Illuminate\Http\Request;

class PlatformHealthController extends Controller
{
    public function show(Request $request, PlatformHealthProbe $probe)
    {
        return response()->json($probe->run());
    }

    /**
     * Create a real in-app notification and broadcast it over Reverb to the current user.
     * Confirms the bell / websocket path end-to-end.
     */
    public function sendReverbTest(
        Request $request,
        RealtimeNotificationBroadcaster $broadcaster,
        PlatformHealthProbe $probe,
    ) {
        $user = $request->user();
        $reachability = $probe->reverbReachability();
        $skipTcpInTests = app()->environment('testing');

        if (! $skipTcpInTests && ! ($reachability['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'broadcast' => false,
                'message' => $reachability['detail'] ?? 'Reverb is not reachable.',
                'reverb' => $reachability,
            ], 422);
        }

        if (! $broadcaster->enabled()) {
            return response()->json([
                'ok' => false,
                'broadcast' => false,
                'message' => 'Broadcasting is disabled (BROADCAST_CONNECTION is null). Set it to reverb.',
                'reverb' => $reachability,
            ], 422);
        }

        $notification = InAppNotification::query()->create([
            'organization_id' => (int) $user->organization_id,
            'user_id' => $user->id,
            'type' => 'info',
            'severity' => 'success',
            'title' => 'Reverb test notification',
            'message' => 'Realtime is working. If the bell updated without a page refresh, Reverb delivered this event.',
            'action_url' => '/platform/health',
            'created_by' => $user->id,
        ]);

        try {
            $broadcaster->notifyCreated($notification, $user);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'broadcast' => false,
                'notification_id' => $notification->id,
                'message' => 'Notification saved but Reverb broadcast failed: '.$e->getMessage(),
                'reverb' => $reachability,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'broadcast' => true,
            'notification_id' => $notification->id,
            'message' => 'Test notification created and broadcast on your private channel. Watch the bell icon update.',
            'reverb' => $reachability,
        ]);
    }
}
