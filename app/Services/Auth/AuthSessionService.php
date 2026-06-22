<?php

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\OrganizationLoginGuard;
use App\Services\Auth\SecuritySettingsResolver;
use App\Services\Erp\CapabilityGate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthSessionService
{
    public function __construct(
        protected TenantAccountResolver $resolver,
        protected UserLoginChannelService $loginChannels,
        protected UserPermissionService $permissions,
        protected OrganizationLoginGuard $organizationLoginGuard,
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
        ?string $activeWorkspaceId = null,
    ): array {
        $org = \App\Models\Organization::findOrFail($currentUser->organization_id);
        $account = $this->resolver->resolveForCanonicalUser($org, (int) $currentUser->id);
        if (! $account) {
            throw ValidationException::withMessages([
                'login_channel' => ['You do not have access to this organization.'],
            ]);
        }

        $currentUser->currentAccessToken()?->delete();

        return $this->issueSession(
            $account,
            $clientId,
            forceLogout: false,
            loginChannel: $loginChannel,
            activeWorkspaceId: $activeWorkspaceId,
        );
    }

    /**
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    protected function issueSession(
        TenantAccount $account,
        string $clientId,
        bool $forceLogout,
        string $loginChannel,
        ?string $activeWorkspaceId = null,
    ): array {
        $authUser = $account->authUser;
        $effective = $account->effectiveUser();
        $loginChannel = $this->loginChannels->normalizeChannel($loginChannel);
        $this->loginChannels->assertCanLogin($effective, $loginChannel);
        $this->assertLoginChannelPermission($effective, $loginChannel);
        $this->assertOrganizationAllowsLoginChannel($account->organization, $loginChannel);
        $this->organizationLoginGuard->assertOrganizationAllowsLogin($account->organization, $effective);

        if (! $effective->is_active || $effective->deleted_at) {
            throw ValidationException::withMessages([
                'username' => ['Your account has been deactivated. Please contact an administrator.'],
            ]);
        }

        if ($forceLogout) {
            $authUser->tokens()->delete();
        } else {
            $this->pruneStaleTokens($authUser);
            $authUser->tokens()->where('name', $clientId)->delete();
            $this->revokeAbandonedTokensElsewhere($authUser, $clientId);
            $this->assertNoActiveSessionElsewhere($authUser, $clientId, $loginChannel);
        }

        $authUser->forceFill(['last_login' => now()])->save();

        $expirationMinutes = SecuritySettingsResolver::tokenExpirationMinutesForChannel($loginChannel);
        $expiresAt = $expirationMinutes !== null ? now()->addMinutes($expirationMinutes) : null;
        $newToken = $authUser->createToken($clientId, ['*'], $expiresAt);
        /** @var PersonalAccessToken $accessToken */
        $accessToken = $newToken->accessToken;
        $accessToken->forceFill([
            'organization_id' => $account->organization->id,
            'user_membership_id' => $account->membership?->id,
            'login_channel' => $loginChannel,
            'active_workspace_id' => $activeWorkspaceId,
        ])->save();

        $memberships = $this->resolver->membershipsForCanonicalUser($account->canonicalUserId());

        $authUser->refresh();
        $effective = $account->effectiveUser();

        return [
            'token' => $newToken->plainTextToken,
            'user' => $effective,
            'organization' => $account->organization,
            'memberships' => $memberships,
            'must_change_password' => (bool) $effective->must_change_password,
        ];
    }

    protected function pruneStaleTokens(User $authUser): void
    {
        $idleMinutes = $this->resolveIdleMinutesForUser($authUser);
        $idleCutoff = now()->subMinutes($idleMinutes);
        // Tokens that were issued but never used (e.g. cookie auth handoff failed).
        $abandonedMinutes = min(2, $idleMinutes);
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

    protected function revokeAbandonedTokensElsewhere(User $authUser, string $clientId): void
    {
        $authUser->tokens()
            ->where('name', '!=', $clientId)
            ->whereNull('last_used_at')
            ->where('created_at', '<', now()->subMinutes(2))
            ->delete();
    }

    protected function assertNoActiveSessionElsewhere(
        User $authUser,
        string $clientId,
        string $loginChannel,
    ): void {
        $idleMinutes = $this->resolveIdleMinutesForUser($authUser);
        $handshakeMinutes = 2;

        $activeTokenExists = $authUser->tokens()
            ->where('name', '!=', $clientId)
            ->where('login_channel', $loginChannel)
            ->where(function ($query) use ($idleMinutes, $handshakeMinutes) {
                $query->where(function ($q) use ($idleMinutes) {
                    $q->whereNotNull('last_used_at')
                        ->where('last_used_at', '>=', now()->subMinutes($idleMinutes));
                })->orWhere(function ($q) use ($handshakeMinutes) {
                    // Short grace for a login still completing on another device.
                    $q->whereNull('last_used_at')
                        ->where('created_at', '>=', now()->subMinutes($handshakeMinutes));
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

    protected function assertOrganizationAllowsLoginChannel(?\App\Models\Organization $organization, string $loginChannel): void
    {
        if ($loginChannel !== UserLoginChannelService::MOBILE || ! $organization) {
            return;
        }

        $gate = (new CapabilityGate)->forOrganization($organization);
        if ($gate->mobileSalesEnabled()) {
            return;
        }

        throw ValidationException::withMessages([
            'login_channel' => ['Mobile sales is not enabled for this organization.'],
        ]);
    }

    protected function resolveIdleMinutesForUser(User $authUser): int
    {
        $orgId = (int) ($authUser->tokens()->value('organization_id') ?? 0);

        return SecuritySettingsResolver::sessionIdleMinutesForOrganizationId($orgId ?: null);
    }
}
