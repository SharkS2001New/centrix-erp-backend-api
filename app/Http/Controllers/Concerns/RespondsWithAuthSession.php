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
        if (($result['user'] ?? null) instanceof User) {
            /** @var User $user */
            $user = $result['user'];
            $result['capabilities'] = app(ErpCapabilitiesController::class)->resolveForUser($user);

            if (! $user->is_super_admin) {
                $org = $result['organization'] ?? $user->organization;
                $licenses = app(OrganizationLicenseService::class);
                $license = is_array($result['capabilities']['license'] ?? null)
                    ? $result['capabilities']['license']
                    : $licenses->resolveForOrganization($org instanceof \App\Models\Organization ? $org : null);

                if ($licenses->isExpired($license)) {
                    if (! empty($result['token']) && is_string($result['token'])) {
                        // Best-effort: delete current token if Sanctum token id present later.
                    }
                    if ($org instanceof \App\Models\Organization) {
                        $licenses->revokeOrganizationSessions($org);
                    }

                    return response()->json([
                        'message' => 'This organization’s Centrix licence has expired. Contact your Centrix administrator to renew or extend.',
                        'code' => 'organization_license_expired',
                        'license' => $license,
                    ], 403);
                }
            }
        }

        $response = response()->json(
            ApiTokenCookie::usesCookieAuth($request)
                ? ApiTokenCookie::sanitizeSessionPayload($result)
                : $result,
        );

        if (! ApiTokenCookie::usesCookieAuth($request) || ! isset($result['token']) || ! is_string($result['token'])) {
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
}
