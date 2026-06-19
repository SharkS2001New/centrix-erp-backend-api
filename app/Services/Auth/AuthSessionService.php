<?php

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\SecuritySettingsResolver;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthSessionService
{
    public function __construct(
        protected TenantAccountResolver $resolver,
        protected UserLoginChannelService $loginChannels,
        protected UserPermissionService $permissions,
    ) {}

    /**
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function login(
        string $companyCode,
        string $username,
        string $password,
        string $clientId,
        bool $forceLogout = false,
        string $loginChannel = UserLoginChannelService::BACKOFFICE,
    ): array {
        $companyCode = strtoupper(trim($companyCode));
        $username = trim($username);

        if ($companyCode === '') {
            return $this->loginPlatformSuperAdminByEmail(
                $username,
                $password,
                $clientId,
                $forceLogout,
                $loginChannel,
            );
        }

        $org = \App\Models\Organization::where('company_code', $companyCode)->first();
        if (! $org) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        $account = $this->resolver->resolve($org, $username);
        if (! $account || ! Hash::check($password, $account->authUser->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        return $this->issueSession($account, $clientId, $forceLogout, $loginChannel);
    }

    /**
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    protected function loginPlatformSuperAdminByEmail(
        string $email,
        string $password,
        string $clientId,
        bool $forceLogout,
        string $loginChannel,
    ): array {
        if (! str_contains($email, '@')) {
            throw ValidationException::withMessages([
                'company_code' => ['Organization code is required.'],
            ]);
        }

        $account = $this->resolver->resolvePlatformSuperAdminByEmail($email);
        if (! $account || ! Hash::check($password, $account->authUser->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        return $this->issueSession($account, $clientId, $forceLogout, $loginChannel);
    }

    /**
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function switchOrganization(
        User $currentUser,
        string $companyCode,
        string $clientId,
        string $loginChannel = UserLoginChannelService::BACKOFFICE,
    ): array
    {
        $companyCode = strtoupper(trim($companyCode));
        $org = \App\Models\Organization::where('company_code', $companyCode)->firstOrFail();

        $canonicalId = (int) $currentUser->id;
        $account = $this->resolver->resolveForCanonicalUser($org, $canonicalId);
        if (! $account) {
            throw ValidationException::withMessages([
                'company_code' => ['You do not have access to this organization.'],
            ]);
        }

        $currentUser->currentAccessToken()?->delete();

        return $this->issueSession($account, $clientId, forceLogout: false, loginChannel: $loginChannel);
    }

    /**
     * Re-issue the session token with a different login channel (workspace switch).
     *
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function switchLoginChannel(
        User $currentUser,
        string $clientId,
        string $loginChannel,
    ): array {
        $org = \App\Models\Organization::findOrFail($currentUser->organization_id);
        $account = $this->resolver->resolveForCanonicalUser($org, (int) $currentUser->id);
        if (! $account) {
            throw ValidationException::withMessages([
                'login_channel' => ['You do not have access to this organization.'],
            ]);
        }

        $currentUser->currentAccessToken()?->delete();

        return $this->issueSession($account, $clientId, forceLogout: false, loginChannel: $loginChannel);
    }

    /**
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    protected function issueSession(
        TenantAccount $account,
        string $clientId,
        bool $forceLogout,
        string $loginChannel,
    ): array {
        $authUser = $account->authUser;
        $effective = $account->effectiveUser();
        $loginChannel = $this->loginChannels->normalizeChannel($loginChannel);
        $this->loginChannels->assertCanLogin($effective, $loginChannel);
        $this->assertLoginChannelPermission($effective, $loginChannel);

        if ($forceLogout) {
            $authUser->tokens()->delete();
        } else {
            $this->pruneStaleTokens($authUser);
            $this->assertNoActiveSessionElsewhere($authUser, $clientId, $loginChannel);
            $authUser->tokens()->where('name', $clientId)->delete();
        }

        $authUser->forceFill(['last_login' => now()])->save();

        $newToken = $authUser->createToken($clientId);
        /** @var PersonalAccessToken $accessToken */
        $accessToken = $newToken->accessToken;
        $accessToken->forceFill([
            'organization_id' => $account->organization->id,
            'user_membership_id' => $account->membership?->id,
            'login_channel' => $loginChannel,
        ])->save();

        $memberships = $this->resolver->membershipsForCanonicalUser($account->canonicalUserId());

        return [
            'token' => $newToken->plainTextToken,
            'user' => $effective,
            'organization' => $account->organization,
            'memberships' => $memberships,
        ];
    }

    protected function pruneStaleTokens(User $authUser): void
    {
        $idleMinutes = $this->resolveIdleMinutesForUser($authUser);
        $idleCutoff = now()->subMinutes($idleMinutes);
        // Tokens that were issued but never used (e.g. closed tab before first API call).
        $abandonedMinutes = min(5, $idleMinutes);
        $abandonedCutoff = now()->subMinutes($abandonedMinutes);

        $authUser->tokens()
            ->where(function ($query) use ($idleCutoff, $abandonedCutoff) {
                $query
                    ->where(function ($q) use ($idleCutoff) {
                        $q->whereNotNull('last_used_at')
                            ->where('last_used_at', '<', $idleCutoff);
                    })
                    ->orWhere(function ($q) use ($idleCutoff) {
                        $q->whereNull('last_used_at')
                            ->where('created_at', '<', $idleCutoff);
                    })
                    ->orWhere(function ($q) use ($abandonedCutoff) {
                        $q->whereNull('last_used_at')
                            ->where('created_at', '<', $abandonedCutoff);
                    });
            })
            ->delete();
    }

    protected function assertNoActiveSessionElsewhere(
        User $authUser,
        string $clientId,
        string $loginChannel,
    ): void {
        $idleMinutes = $this->resolveIdleMinutesForUser($authUser);

        $activeTokenExists = $authUser->tokens()
            ->where('name', '!=', $clientId)
            ->where('login_channel', $loginChannel)
            ->where(function ($query) use ($idleMinutes) {
                $query->where('last_used_at', '>=', now()->subMinutes($idleMinutes))
                    ->orWhere(function ($q) use ($idleMinutes) {
                        $q->whereNull('last_used_at')
                            ->where('created_at', '>=', now()->subMinutes($idleMinutes));
                    });
            })
            ->exists();

        if ($activeTokenExists) {
            throw ValidationException::withMessages([
                'session' => ['This user is already logged in on another device.'],
            ]);
        }
    }

    protected function assertLoginChannelPermission(User $user, string $loginChannel): void
    {
        if ($loginChannel !== UserLoginChannelService::POS) {
            return;
        }

        if ($this->permissions->hasPermission($user, 'pos.terminal.view')) {
            return;
        }

        throw ValidationException::withMessages([
            'login_channel' => ['You do not have permission to use the cashier terminal.'],
        ]);
    }

    protected function resolveIdleMinutesForUser(User $authUser): int
    {
        $orgId = (int) ($authUser->tokens()->value('organization_id') ?? 0);

        return SecuritySettingsResolver::sessionIdleMinutesForOrganizationId($orgId ?: null);
    }
}
