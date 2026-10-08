<?php

namespace App\Http\Middleware;

use App\Services\Platform\OrganizationLicenseService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
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

        $license = $this->licenses->resolveForOrganization($org);
        $mode = $this->licenses->accessMode($license);
        $chargesToken = $mode === 'token' && $this->requestUsesToken($request);

        if ($chargesToken && ! $this->licenses->reserveToken((int) $org->id)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This organisation has no Centrix tokens left. A create, update, or delete uses 1 token. Reading is still free on products, customers, suppliers, and sales reports until you top up.',
                'code' => 'organization_tokens_depleted',
                'license' => $this->licenses->resolveForOrganization($org),
            ], 403));
        }

        if ($this->licenses->needsPayment($license) && ! $this->licenses->allowsLimitedRoute($path)) {
            throw new HttpResponseException(response()->json([
                'message' => $mode === 'expired'
                    ? 'This Centrix licence has expired. Pay on the Centrix Paybill to restore the rest of the system. Products, customers, suppliers, and sales reports stay available.'
                    : 'This organisation is on the free Centrix plan. Pay on the Centrix Paybill to open the rest of the system. Products, customers, suppliers, and sales reports stay available.',
                'code' => 'organization_license_upgrade_required',
                'license' => $license,
            ], 403));
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            if ($chargesToken) {
                $this->licenses->refundToken((int) $org->id);
            }
            throw $e;
        }

        if ($chargesToken && $response->getStatusCode() >= 400) {
            $this->licenses->refundToken((int) $org->id);
        }

        return $response;
    }

    /** Create, update, and delete spend one token. Reads do not. */
    protected function requestUsesToken(Request $request): bool
    {
        return in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
