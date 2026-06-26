<?php

namespace App\Http\Middleware;

use App\Services\Auth\ApiTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiTokenCookie
{
    /** @var list<string> */
    protected array $cookieBypassPaths = [
        'api/v1/auth/login',
        'api/v1/auth/logout',
        'api/v1/auth/forgot-password',
        'api/v1/auth/reset-password',
        'api/v1/health',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiTokenCookie::enabled()) {
            return $next($request);
        }

        if ($this->shouldBypassCookieAuth($request)) {
            return $next($request);
        }

        if (! $request->bearerToken()) {
            $token = $request->cookie((string) config('security.api_token_cookie.name', 'centrix_api_token'));
            if (is_string($token) && $token !== '') {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }

    protected function shouldBypassCookieAuth(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        foreach ($this->cookieBypassPaths as $bypassPath) {
            if ($path === $bypassPath) {
                return true;
            }
        }

        return false;
    }
}
