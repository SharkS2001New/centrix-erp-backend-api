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

        if ($this->licenses->blocksUnpaidAccess($license)) {
            $missing = ! $license || ($license['status'] ?? '') === 'missing';
            throw new HttpResponseException(response()->json([
                'message' => $missing
                    ? 'This organization does not have an active Centrix subscription. Contact your Centrix administrator to activate a plan.'
                    : 'This organization’s Centrix licence has expired. Contact your Centrix administrator to renew or extend.',
                'code' => $missing ? 'organization_subscription_required' : 'organization_license_expired',
                'license' => $license,
            ], 403));
        }

        $paymentOnly = $this->licenses->requiresPaymentOnly($license, $org);
        $onPackageOne = ! $paymentOnly && $this->licenses->usesPackageOne($license);

        $allowed = $paymentOnly
            ? $this->licenses->allowsPaymentOnlyRoute($path)
            : ($onPackageOne
                ? app(FreePlanAccess::class)->allowsRoute($org, $path)
                : $this->licenses->allowsLimitedRoute($path));
        if (($paymentOnly || $onPackageOne || $this->licenses->needsPayment($license)) && ! $allowed) {
            $planName = trim((string) ($license['plan_name'] ?? ''));
            $freeName = trim((string) (($license['current_package'] ?? null) ?: ($license['free_plan']['name'] ?? null) ?: app(FreePlanAccess::class)->name()));
            $expiredMessage = $paymentOnly
                ? ($planName !== ''
                    ? "Your Instant trial of {$planName} has ended. Choose a monthly or yearly plan to continue using CentrixERP."
                    : 'Your Instant trial has ended. Choose a monthly or yearly plan to continue using CentrixERP.')
                : ($planName !== ''
                    ? "Your trial or paid period for {$planName} has ended. This organisation is now on {$freeName}, the free plan. Pay on the Centrix Paybill to open that package again."
                    : "Your trial or paid period has ended. This organisation is now on {$freeName}, the free plan. Pay on the Centrix Paybill to open a bigger plan again.");
            throw new HttpResponseException(response()->json([
                'message' => ($license['status'] ?? '') === 'scheduled'
                    ? 'This Centrix licence opens at '.($license['starts_at'] ?? 'the scheduled time').'.'
                    : ($mode === 'expired'
                        ? $expiredMessage
                        : "This organisation is on {$freeName}, the free plan. Open a bigger plan to use the rest of Centrix."),
                'code' => 'organization_license_upgrade_required',
                'license' => $license,
            ], 403));
        }

        return $next($request);
    }
}
