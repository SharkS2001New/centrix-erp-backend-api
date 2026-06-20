<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActAsOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->route('organization');
        $platformCode = config('erp.platform_company_code', 'PLATFORM');

        Organization::query()
            ->where('id', $organizationId)
            ->where('company_code', '!=', $platformCode)
            ->firstOrFail();

        $request->attributes->set('acting_organization_id', (int) $organizationId);

        return $next($request);
    }
}
