<?php

namespace App\Http\Middleware;

use App\Services\Platform\FreePlanAccess;
use App\Services\Platform\OrganizationLicenseService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationLicenseActive
{
    public function __construct(
        protected OrganizationLicenseService $licenses,
    ) {}

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

        $license = $this->licenses->resolveForOrganization($org);
        $mode = $this->licenses->accessMode($license);
        $onPackageOne = $this->licenses->usesPackageOne($license);

        $allowed = $onPackageOne
            ? app(FreePlanAccess::class)->allowsRoute($org, $path)
            : $this->licenses->allowsLimitedRoute($path);
        if (($onPackageOne || $this->licenses->needsPayment($license)) && ! $allowed) {
            throw new HttpResponseException(response()->json([
                'message' => ($license['status'] ?? '') === 'scheduled'
                    ? 'This Centrix licence opens at '.($license['starts_at'] ?? 'the scheduled time').'.'
                    : ($mode === 'expired'
                        ? 'This Centrix licence has expired. Pay on the Centrix Paybill to restore the rest of the system. Products, customers, suppliers, and sales reports stay available.'
                        : 'This organisation is on Package 1. Open a bigger plan to use the rest of Centrix.'),
                'code' => 'organization_license_upgrade_required',
                'license' => $license,
            ], 403));
        }

        return $next($request);
    }
}
