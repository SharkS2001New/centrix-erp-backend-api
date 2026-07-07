<?php

namespace App\Services\Mobile;

use App\Models\User;
use App\Models\UserDeviceToken;

class UserDeviceTokenService
{
    public function register(
        User $user,
        string $token,
        string $appChannel,
        ?string $platform = null,
    ): UserDeviceToken {
        $token = trim($token);
        abort_if($token === '', 422, 'Device token is required.');
        abort_if(! in_array($appChannel, $this->allowedChannels(), true), 422, 'Invalid app channel.');
        abort_if(! $user->organization_id, 422, 'User organization is required for device registration.');

        // A device token belongs to one active org session at a time.
        UserDeviceToken::query()
            ->where('token', $token)
            ->where('app_channel', $appChannel)
            ->where(function ($query) use ($user) {
                $query->where('user_id', '!=', $user->id)
                    ->orWhere('organization_id', '!=', $user->organization_id);
            })
            ->delete();

        return UserDeviceToken::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'token' => $token,
                'app_channel' => $appChannel,
            ],
            [
                'organization_id' => (int) $user->organization_id,
                'platform' => $platform ? strtolower(trim($platform)) : null,
                'last_seen_at' => now(),
            ],
        );
    }

    public function unregister(User $user, string $token, ?string $appChannel = null): void
    {
        $query = UserDeviceToken::query()
            ->where('user_id', $user->id)
            ->where('token', trim($token));

        if ($appChannel !== null) {
            $query->where('app_channel', $appChannel);
        }

        $query->delete();
    }

    /** @return list<string> */
    public function tokensForUser(User $user, string $appChannel): array
    {
        return UserDeviceToken::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->where('app_channel', $appChannel)
            ->orderByDesc('last_seen_at')
            ->pluck('token')
            ->all();
    }

    /** @return list<string> */
    public function allowedChannels(): array
    {
        return [
            UserDeviceToken::CHANNEL_MANAGER,
            UserDeviceToken::CHANNEL_MOBILE_SALES,
        ];
    }
}
