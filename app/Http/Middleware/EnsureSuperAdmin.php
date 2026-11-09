<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformAdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();
        if (! $user?->is_super_admin) {
            return response()->json(['message' => 'Platform super admin access required.'], 403);
        }

        if (
            config('erp.platform_require_two_factor', true)
            && ! PlatformAdminPermissions::userHasTwoFactor($user)
        ) {
            return response()->json([
                'message' => 'Platform administrators must enable two-factor authentication before using platform tools.',
                'code' => 'platform_two_factor_required',
            ], 403);
        }

        $area = $permission ?: PlatformAdminPermissions::areaForRequest($request);
        if ($area !== null && ! PlatformAdminPermissions::userAllows($user, $area)) {
            return response()->json([
                'message' => 'You do not have permission for this platform area.',
                'code' => 'platform_permission_denied',
                'required_permission' => $area,
            ], 403);
        }

        return $next($request);
    }
}
