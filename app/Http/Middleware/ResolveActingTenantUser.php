<?php

namespace App\Http\Middleware;

use App\Models\UserMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActingTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user || ! $token || ! $token->organization_id) {
            return $next($request);
        }

        $orgId = (int) $token->organization_id;
        if ((int) $user->organization_id === $orgId) {
            return $next($request);
        }

        $membership = $token->user_membership_id
            ? UserMembership::find($token->user_membership_id)
            : null;

        if ($membership) {
            $user->organization_id = $membership->organization_id;
            $user->branch_id = $membership->branch_id;
            $user->role_id = $membership->role_id;
            $user->username = $membership->username;
            $user->is_admin = $membership->is_admin;
            $user->access_scope = $membership->access_scope;
            $user->is_active = $membership->is_active;
        }

        return $next($request);
    }
}
