<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrgProvisioningAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('erp.allow_org_provisioning')) {
            return response()->json([
                'message' => 'New organization provisioning is disabled on this environment.',
                'code' => 'org_provisioning_disabled',
            ], 403);
        }

        return $next($request);
    }
}
