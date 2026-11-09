<?php

namespace App\Http\Middleware;

use App\Models\UserMembership;
use App\Support\SanctumTokenResolver;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ltrim($request->path(), '/') === 'api/v1/health') {
            return $next($request);
        }

        $accessToken = SanctumTokenResolver::fromRequest($request);
        if (! $accessToken) {
            return $next($request);
        }

        $verdictKey = 'pat.active:'.$accessToken->id;
        $cachedVerdict = Cache::get($verdictKey);
        if ($cachedVerdict === 'ok') {
            return $next($request);
        }
        if (is_array($cachedVerdict) && isset($cachedVerdict['status'], $cachedVerdict['body'])) {
            return response()->json($cachedVerdict['body'], (int) $cachedVerdict['status']);
        }

        /** @var \App\Models\User|null $user */
        $user = $accessToken->tokenable;
        if (! $user) {
            $accessToken->delete();

            return response()->json([
                'message' => 'Your session is no longer valid. Please sign in again.',
                'code' => 'session_invalid',
            ], 401);
        }

        try {
            $user->refresh();
        } catch (ModelNotFoundException) {
            $accessToken->delete();

            return response()->json([
                'message' => 'Your session is no longer valid. Please sign in again.',
                'code' => 'session_invalid',
            ], 401);
        }

        if (! $user->is_active || $user->deleted_at) {
            $accessToken->delete();

            return response()->json([
                'message' => 'Your account has been deactivated. Please contact an administrator.',
                'code' => 'account_inactive',
            ], 403);
        }

        if ($accessToken->user_membership_id) {
            $membership = UserMembership::find($accessToken->user_membership_id);
            if (! $membership?->is_active) {
                $accessToken->delete();
                Cache::forget($verdictKey);

                return response()->json([
                    'message' => 'Your access to this organization has been deactivated.',
                    'code' => 'membership_inactive',
                ], 403);
            }
        }

        Cache::put($verdictKey, 'ok', 45);

        return $next($request);
    }
}
