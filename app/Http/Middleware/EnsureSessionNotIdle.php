<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionNotIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();
        if (! $plainTextToken) {
            return $next($request);
        }

        $accessToken = Sanctum::personalAccessTokenModel()::findToken($plainTextToken);
        if (! $accessToken) {
            return $next($request);
        }

        $idleMinutes = \App\Services\Auth\SecuritySettingsResolver::sessionIdleMinutesForOrganizationId(
            (int) ($accessToken->organization_id ?? 0) ?: null,
        );
        $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

        if ($lastActivity && $lastActivity->lt(now()->subMinutes($idleMinutes))) {
            $accessToken->delete();

            return response()->json([
                'message' => 'Your session expired due to inactivity. Please sign in again.',
                'code' => 'session_idle_timeout',
            ], 401);
        }

        return $next($request);
    }
}
