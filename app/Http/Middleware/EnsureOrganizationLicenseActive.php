<?php

namespace App\Http\Middleware;

use App\Services\Platform\OrganizationLicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationLicenseActive
{
    public function __construct(protected OrganizationLicenseService $licenses) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = ltrim($request->path(), '/');
        if (
            str_starts_with($path, 'api/v1/auth/')
            || str_starts_with($path, 'api/v1/admin/')
            || $path === 'api/v1/health'
            || str_starts_with($path, 'api/v1/system-issue-reports')
        ) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || $user->is_super_admin) {
            return $next($request);
        }

        $org = $user->organization;
        if (! $org && method_exists($user, 'loadMissing')) {
            $user->loadMissing('organization');
            $org = $user->organization;
        }

        $this->licenses->assertUsable($org, $user);

        return $next($request);
    }
}
