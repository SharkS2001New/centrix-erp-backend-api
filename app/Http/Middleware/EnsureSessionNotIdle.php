<?php

namespace App\Http\Middleware;

use App\Support\SanctumTokenResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionNotIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        $nightPolicy = app(\App\Services\Auth\NightSessionPolicy::class);
        $nightSessionActive = $nightPolicy->isActive();
        if (! config('security.revoke_idle_tokens', false) && ! $nightSessionActive) {
            return $next($request);
        }

        $accessToken = SanctumTokenResolver::fromRequest($request);
        if (! $accessToken) {
            return $next($request);
        }

        // Machine tokens for CentrixAttendanceAgent — must survive overnight PC sleep
        // and must not be treated as interactive browser sessions.
        if (\App\Support\LocalAgentTokens::isAnyAgentTokenName($accessToken->name ?? null)) {
            return $next($request);
        }

        $idleMinutes = \App\Services\Auth\SecuritySettingsResolver::sessionIdleMinutesForOrganizationId(
            (int) ($accessToken->organization_id ?? 0) ?: null,
        );
        if ($nightSessionActive) {
            $idleMinutes = min($idleMinutes, $nightPolicy->idleMinutes());
        }
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
