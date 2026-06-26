<?php

namespace App\Http\Middleware;

use App\Models\UserMembership;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ltrim($request->path(), '/') === 'api/v1/health') {
            return $next($request);
        }

        $plainTextToken = $request->bearerToken();
        if (! $plainTextToken) {
            return $next($request);
        }

        $accessToken = Sanctum::personalAccessTokenModel()::findToken($plainTextToken);
        if (! $accessToken) {
            return $next($request);
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

                return response()->json([
                    'message' => 'Your access to this organization has been deactivated.',
                    'code' => 'membership_inactive',
                ], 403);
            }
        }

        return $next($request);
    }
}
