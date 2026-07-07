<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\Mobile\FcmPushNotificationService;
use App\Services\Mobile\FcmPushSettingsResolver;
use Illuminate\Http\Request;

class PlatformFcmPushController extends Controller
{
    public function show(FcmPushNotificationService $push)
    {
        return response()->json([
            ...FcmPushSettingsResolver::describe(),
            'diagnostics' => $push->diagnose(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'fcm_project_id' => 'sometimes|nullable|string|max:120',
            'ignore_local_tokens' => 'sometimes|boolean',
            'credentials_json' => 'sometimes|nullable|string|max:20000',
            'clear_credentials' => 'sometimes|boolean',
        ]);

        $description = FcmPushSettingsResolver::savePlatform($data);

        return response()->json([
            ...$description,
            'diagnostics' => app(FcmPushNotificationService::class)->diagnose(),
        ]);
    }

    public function test(Request $request, FcmPushNotificationService $push)
    {
        $data = $request->validate([
            'user_id' => 'required_without:token|integer|exists:users,id',
            'token' => 'required_without:user_id|string|max:4096',
            'app' => 'sometimes|string|in:manager,mobile_sales,mobile,sales',
        ]);

        $status = $push->diagnose();
        if (! ($status['ready'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => 'Push is not ready. Check configuration and credentials.',
                'diagnostics' => $status,
            ], 422);
        }

        $appChannel = match (strtolower((string) ($data['app'] ?? 'manager'))) {
            'mobile', 'mobile_sales', 'sales' => UserDeviceToken::CHANNEL_MOBILE_SALES,
            default => UserDeviceToken::CHANNEL_MANAGER,
        };

        $tokens = [];
        if (! empty($data['token'])) {
            $tokens = [trim((string) $data['token'])];
        } else {
            $user = User::query()->findOrFail((int) $data['user_id']);
            $tokens = app(\App\Services\Mobile\UserDeviceTokenService::class)
                ->tokensForUser($user, $appChannel);
        }

        $settings = FcmPushSettingsResolver::resolve();
        if ($settings['ignore_local_tokens'] ?? true) {
            $tokens = array_values(array_filter(
                $tokens,
                fn (string $token) => ! str_starts_with($token, 'mgr-local-')
                    && ! str_starts_with($token, 'mob-local-'),
            ));
        }

        if ($tokens === []) {
            return response()->json([
                'ok' => false,
                'message' => 'No device tokens found for the selected user and app channel.',
            ], 422);
        }

        $dataType = $appChannel === UserDeviceToken::CHANNEL_MOBILE_SALES
            ? 'approval_outcome'
            : 'approval';

        $results = [];
        foreach ($tokens as $token) {
            $results[] = $push->sendTest($token, dataType: $dataType);
        }

        $ok = collect($results)->every(fn (array $result) => (bool) ($result['ok'] ?? false));

        return response()->json([
            'ok' => $ok,
            'app_channel' => $appChannel,
            'results' => $results,
            'diagnostics' => $status,
        ], $ok ? 200 : 422);
    }
}
