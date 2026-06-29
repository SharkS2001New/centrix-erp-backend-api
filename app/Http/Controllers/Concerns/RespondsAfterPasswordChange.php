<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\Api\V1\ErpCapabilitiesController;
use App\Models\User;
use App\Services\Auth\PasswordExpiryService;
use App\Services\Cache\OrganizationCache;
use Illuminate\Http\JsonResponse;

trait RespondsAfterPasswordChange
{
    protected function respondAfterPasswordChange(User $user, string $message = 'Password updated successfully.'): JsonResponse
    {
        $user->refresh();

        $orgId = (int) ($user->organization_id ?? 0);
        if ($orgId > 0) {
            OrganizationCache::forget($orgId, 'capabilities:user:v2:'.(int) $user->id);
        }

        $passwordExpiry = app(PasswordExpiryService::class)->statusForUser($user);

        return response()->json([
            'message' => $message,
            'must_change_password' => false,
            'user' => $user->only([
                'id',
                'username',
                'email',
                'full_name',
                'must_change_password',
                'organization_id',
                'branch_id',
                'role_id',
                'access_scope',
                'is_admin',
            ]),
            'password_expiry' => $passwordExpiry,
            'capabilities' => app(ErpCapabilitiesController::class)->resolveForUser($user),
        ]);
    }
}
