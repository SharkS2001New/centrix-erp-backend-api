<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\Api\V1\ErpCapabilitiesController;
use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
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
            $result['capabilities'] = app(ErpCapabilitiesController::class)->resolveForUser($result['user']);
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
