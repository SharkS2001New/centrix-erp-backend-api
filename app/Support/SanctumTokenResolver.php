<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Resolve the Bearer Sanctum token once per request for the Ensure* API middleware stack.
 */
class SanctumTokenResolver
{
    public const ATTR = 'centrix.sanctum_access_token';

    public static function fromRequest(Request $request): ?PersonalAccessToken
    {
        if ($request->attributes->has(self::ATTR)) {
            $cached = $request->attributes->get(self::ATTR);

            return $cached instanceof PersonalAccessToken ? $cached : null;
        }

        $plainTextToken = $request->bearerToken();
        if (! $plainTextToken) {
            $request->attributes->set(self::ATTR, null);

            return null;
        }

        /** @var PersonalAccessToken|null $accessToken */
        $accessToken = Sanctum::personalAccessTokenModel()::findToken($plainTextToken);
        $request->attributes->set(self::ATTR, $accessToken);

        return $accessToken instanceof PersonalAccessToken ? $accessToken : null;
    }
}
