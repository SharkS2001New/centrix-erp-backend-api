<?php

namespace App\Http\Middleware;

use App\Services\Auth\ApiTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiTokenCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiTokenCookie::enabled()) {
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
}
