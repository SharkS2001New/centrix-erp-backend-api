<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operational organization settings (sales, finance, security, etc.) are edited
 * from the platform super-admin console, not tenant ERP sessions.
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
