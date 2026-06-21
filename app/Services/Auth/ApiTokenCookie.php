<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class ApiTokenCookie
{
    public static function enabled(): bool
    {
        return (bool) config('security.api_token_cookie.enabled', false);
    }

    public static function usesCookieAuth(Request $request): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $channel = (string) ($request->input('login_channel') ?? $request->header('X-Login-Channel', 'backoffice'));

        return $channel !== UserLoginChannelService::MOBILE;
    }

    public static function attach(string $plainTextToken): Cookie
    {
        $minutes = (int) config('security.sanctum_token_expiration_minutes', 60 * 24);
        $domain = config('security.api_token_cookie.domain');
        $sameSite = strtolower((string) config('security.api_token_cookie.same_site', 'lax'));

        return cookie(
            (string) config('security.api_token_cookie.name', 'centrix_api_token'),
            $plainTextToken,
            $minutes > 0 ? $minutes : 60 * 24 * 7,
            '/',
            is_string($domain) && $domain !== '' ? $domain : null,
            (bool) config('security.api_token_cookie.secure', false),
            true,
            false,
            in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax',
        );
    }

    public static function forget(): Cookie
    {
        $domain = config('security.api_token_cookie.domain');
        $sameSite = strtolower((string) config('security.api_token_cookie.same_site', 'lax'));

        return cookie(
            (string) config('security.api_token_cookie.name', 'centrix_api_token'),
            '',
            -1,
            '/',
            is_string($domain) && $domain !== '' ? $domain : null,
            (bool) config('security.api_token_cookie.secure', false),
            true,
            false,
            in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitizeSessionPayload(array $payload): array
    {
        if (array_key_exists('token', $payload)) {
            $payload['token'] = null;
        }

        return $payload;
    }
}
