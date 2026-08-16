<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\Api\V1\ErpCapabilitiesController;
use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use App\Services\Platform\OrganizationLicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsWithAuthSession
{
    /**
     * @param  array<string, mixed>  $result
     */
    protected function respondWithAuthSession(array $result, Request $request): JsonResponse
    {
        $authChannel = $this->resolveAuthLoginChannel($request, $result);

        if (($result['user'] ?? null) instanceof User) {
            /** @var User $user */
            $user = $result['user'];
            $result['capabilities'] = app(ErpCapabilitiesController::class)->resolveForUser(
                $user,
                $authChannel,
            );

            // PIN operator switch continues an already-open till session.
            if (! $user->is_super_admin && empty($result['operator_switch'])) {
                $org = $result['organization'] ?? $user->organization;
                $licenses = app(OrganizationLicenseService::class);
                $license = is_array($result['capabilities']['license'] ?? null)
                    ? $result['capabilities']['license']
                    : $licenses->resolveForOrganization($org instanceof \App\Models\Organization ? $org : null);

                if ($licenses->isPlatformOrganization($org instanceof \App\Models\Organization ? $org : null)) {
                    // Platform tenant users are not subscription-gated.
                } elseif ($licenses->isExpired($license)) {
                    if ($org instanceof \App\Models\Organization) {
                        $licenses->revokeOrganizationSessions($org);
                    }

                    $missing = ! $license || ($license['status'] ?? '') === 'missing';

                    return response()->json([
                        'message' => $missing
                            ? 'This organization does not have an active Centrix subscription. Contact your Centrix administrator to activate a plan.'
                            : 'This organization’s Centrix licence has expired. Contact your Centrix administrator to renew or extend.',
                        'code' => $missing ? 'organization_subscription_required' : 'organization_license_expired',
                        'license' => $license,
                    ], 403);
                }
            }

            // Profile array includes has_logo / logo_file_path for document print branding.
            if (($result['organization'] ?? null) instanceof \App\Models\Organization) {
                $result['organization'] = $result['organization']->toProfileArray();
            }

            // Enrich login user with mobile route lock fields (assigned route name, etc.).
            $result['user'] = array_merge(
                $user->toArray(),
                app(\App\Services\Auth\UserMobileOrderScopeService::class)->mobileContext($user),
            );
        }

        unset($result['operator_switch']);

        // Prefer the channel on the issued session (MFA verify has no login_channel body
        // field). Mobile/manager must keep the bearer token in JSON — cookie-only strip
        // caused "Authentication did not return a token" in Centrix Manager.
        $useCookieAuth = ApiTokenCookie::usesCookieAuth($request, $authChannel);
        $response = response()->json(
            $useCookieAuth
                ? ApiTokenCookie::sanitizeSessionPayload($result)
                : $result,
        );

        if (! $useCookieAuth || ! isset($result['token']) || ! is_string($result['token'])) {
            return $response;
        }

        return $response->withCookie(ApiTokenCookie::attach($result['token']));
    }

    protected function respondWithAuthLogout(): JsonResponse
    {
        $response = response()->json(['ok' => true]);

        if (! ApiTokenCookie::enabled()) {
            return $response;
        }

        return $response->withCookie(ApiTokenCookie::forget());
    }

    /**
     * Prefer the channel used for this auth response so login-embedded capabilities
     * are slimmed for mobile/manager even before the Bearer token is on the request.
     *
     * @param  array<string, mixed>  $result
     */
    protected function resolveAuthLoginChannel(Request $request, array $result): ?string
    {
        $fromBody = strtolower(trim((string) $request->input('login_channel', '')));
        if (in_array($fromBody, ['backoffice', 'pos', 'mobile', 'manager'], true)) {
            return $fromBody;
        }

        $fromHeader = strtolower(trim((string) $request->header('X-Login-Channel', '')));
        if (in_array($fromHeader, ['backoffice', 'pos', 'mobile', 'manager'], true)) {
            return $fromHeader;
        }

        $fromResult = strtolower(trim((string) ($result['login_channel'] ?? '')));
        if (in_array($fromResult, ['backoffice', 'pos', 'mobile', 'manager'], true)) {
            return $fromResult;
        }

        $token = $result['token'] ?? null;
        if (is_string($token) && $token !== '') {
            // Newly issued Sanctum token is not yet currentAccessToken on $user.
            $user = $result['user'] ?? null;
            if ($user instanceof User) {
                $latest = $user->tokens()->latest('id')->first();
                $channel = strtolower((string) ($latest?->login_channel ?? ''));
                if ($channel !== '') {
                    return $channel;
                }
            }
        }

        return null;
    }
}
