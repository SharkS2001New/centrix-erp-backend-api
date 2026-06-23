<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reserved for platform-only organization settings routes (e.g. legacy archive).
 * Module settings use tenant admin routes; platform-controlled keys are stripped in controllers.
 */
class ForbidTenantOrganizationSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        return response()->json([
            'message' => 'Organization settings are managed by the platform administrator.',
        ], 403);
    }
}
