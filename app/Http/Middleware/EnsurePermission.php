<?php

namespace App\Http\Middleware;

use App\Services\Auth\UserPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->is_admin) {
            return $next($request);
        }

        $service = app(UserPermissionService::class);
        $codes = str_contains($permission, '|')
            ? array_map('trim', explode('|', $permission))
            : [$permission];

        foreach ($codes as $code) {
            if ($service->hasPermission($user, $code)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
            'permission' => $permission,
        ], 403);
    }
}
