<?php

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\PasswordExpiryService;
use App\Services\Auth\OrganizationLoginGuard;
use App\Services\Auth\SecuritySettingsResolver;
use App\Services\Auth\TwoFactorService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\IndustryRegistry;
use App\Services\Platform\PlatformMailSettingsResolver;
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

        $org = \App\Models\Organization::findByCompanyCodeIdentifier($companyCode);
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

        return $this->continueAfterPassword($account, $clientId, $forceLogout, $loginChannel);
    }

    /**
     * Hotel terminal PIN sign-in. Username is already bound to the device after the
     * first password login; PIN issues a new session without a second MFA challenge.
     *
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function loginWithPin(
        string $companyCode,
        string $username,
        string $pin,
        string $clientId,
        bool $forceLogout = false,
        string $loginChannel = UserLoginChannelService::BACKOFFICE,
    ): array {
        $companyCode = strtoupper(trim($companyCode));
        $username = trim($username);

        if ($companyCode === '' || $username === '') {
            throw ValidationException::withMessages([
                'username' => ['Organization and username are required for PIN sign-in.'],
            ]);
        }

        $org = \App\Models\Organization::findByCompanyCodeIdentifier($companyCode);
        if (! $org) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        if (! IndustryRegistry::isHospitality($org->deployment_profile)) {
            throw ValidationException::withMessages([
                'pin' => ['PIN sign-in is only available for Hotel & Hospitality.'],
            ]);
        }

        $settings = SecuritySettingsResolver::forOrganization($org);
        if (! ($settings['enable_pin_unlock'] ?? true)) {
            throw ValidationException::withMessages([
                'pin' => ['PIN sign-in is turned off for this organization. Sign in with your password.'],
            ]);
        }

        $account = $this->resolver->resolve($org, $username);
        if (! $account) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        $pins = app(PinLoginService::class);
        $effective = $account->effectiveUser();
        if (! $pins->userHasPin($effective)) {
            throw ValidationException::withMessages([
                'pin' => ['This account does not have a screen PIN. Sign in with your password.'],
            ]);
        }

        $pins->assertPinMatches($effective, $pin, 'pin');

        return $this->issueSession($account, $clientId, $forceLogout, $loginChannel);
    }

    /**
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}|array{mfa_required: bool, code: string, challenge_token: string, method: string, email_hint: ?string, expires_in: int}
     */
    protected function continueAfterPassword(
        TenantAccount $account,
        string $clientId,
        bool $forceLogout,
        string $loginChannel,
    ): array {
        $effective = $account->effectiveUser();
        $twoFactor = app(TwoFactorService::class);
        if ($twoFactor->isEnabled($effective)) {
            if (
                $effective->two_factor_method === TwoFactorService::METHOD_EMAIL
                && ! PlatformMailSettingsResolver::canDeliverAuthMail()
            ) {
                // Superadmin must still be able to sign in to re-enable email delivery.
                if ($effective->is_super_admin) {
                    $session = $this->issueSession($account, $clientId, $forceLogout, $loginChannel);
                    $session['warnings'] = [[
                        'code' => 'platform_email_disabled',
                        'message' => 'Notification email (2FA / system alerts) is not configured, so email two-factor was skipped. Set it under Settings → Email delivery → Notifications.',
                        'action_url' => '/platform/settings?tab=email&email_tab=auth',
                    ]];

                    return $session;
                }

                throw ValidationException::withMessages([
                    'username' => [
                        'Sign-in verification email cannot be sent because notification SMTP is not configured. Ask a platform administrator to set Settings → Email delivery → Notifications.',
                    ],
                ]);
            }

            return $twoFactor->startLoginChallenge($account, $clientId, $forceLogout, $loginChannel);
        }

        return $this->issueSession($account, $clientId, $forceLogout, $loginChannel);
    }

    /**
     * Passwordless passkey login (GitHub-style). User verification on the authenticator
     * satisfies MFA — no separate 2FA challenge.
     *
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function loginWithPasskey(
        string $challengeToken,
        array $credential,
        string $clientId,
        bool $forceLogout = false,
        string $loginChannel = UserLoginChannelService::BACKOFFICE,
    ): array {
        $verified = app(PasskeyService::class)->completeLogin($challengeToken, $credential);
        $user = $verified['user'];
        $org = \App\Models\Organization::query()->findOrFail($user->organization_id);
        $account = $this->resolver->resolveForCanonicalUser($org, (int) $user->id);
        if (! $account) {
            throw ValidationException::withMessages([
                'credential' => ['Unable to complete sign-in for this account.'],
            ]);
        }

        return $this->issueSession($account, $clientId, $forceLogout, $loginChannel);
    }

    /**
     * Complete MFA using a registered passkey instead of TOTP/email code.
     *
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function completeTwoFactorLoginWithPasskey(
        string $passkeyChallengeToken,
        array $credential,
    ): array {
        $verified = app(PasskeyService::class)->completeTwoFactorAssertion($passkeyChallengeToken, $credential);
        $org = \App\Models\Organization::query()->findOrFail($verified['organization_id']);
        $account = $this->resolver->resolveForCanonicalUser($org, (int) $verified['user_id']);
        if (! $account) {
            throw ValidationException::withMessages([
                'credential' => ['Unable to complete sign-in for this account.'],
            ]);
        }

        return $this->issueSession(
            $account,
            $verified['client_id'],
            $verified['force_logout'],
            $verified['login_channel'],
        );
    }

    /**
     * Complete login after a successful 2FA challenge.
     *
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function completeTwoFactorLogin(
        string $challengeToken,
        string $code,
    ): array {
        $verified = app(TwoFactorService::class)->verifyLoginChallenge($challengeToken, $code);
        $org = \App\Models\Organization::query()->findOrFail($verified['organization_id']);
        $account = $this->resolver->resolveForCanonicalUser($org, (int) $verified['user_id']);
        if (! $account) {
            throw ValidationException::withMessages([
                'code' => ['Unable to complete sign-in for this account.'],
            ]);
        }

        return $this->issueSession(
            $account,
            $verified['client_id'],
            $verified['force_logout'],
            $verified['login_channel'],
        );
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

        return $this->continueAfterPassword($account, $clientId, $forceLogout, $loginChannel);
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
        $org = \App\Models\Organization::findByCompanyCodeIdentifier($companyCode);
        if (! $org) {
            throw ValidationException::withMessages([
                'company_code' => ['You do not have access to this organization.'],
            ]);
        }

        $canonicalId = (int) $currentUser->id;
        $account = $this->resolver->resolveForCanonicalUser($org, $canonicalId);
        if (! $account) {
            throw ValidationException::withMessages([
                'company_code' => ['You do not have access to this organization.'],
            ]);
        }

        $currentUser->currentAccessToken()?->delete();

        return $this->issueSession(
            $account,
            $clientId,
            forceLogout: false,
            loginChannel: $loginChannel,
            skipSingleSessionCheck: true,
        );
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

        $loginChannel = $this->loginChannels->normalizeChannel($loginChannel);
        $this->loginChannels->assertCanLogin($account->effectiveUser(), $loginChannel);
        $this->assertLoginChannelPermission($account->effectiveUser(), $loginChannel);
        $this->assertOrganizationAllowsLoginChannel($account->organization, $loginChannel);

        /** @var PersonalAccessToken|null $currentToken */
        $currentToken = $currentUser->currentAccessToken();
        $currentChannel = $currentToken?->login_channel ?? UserLoginChannelService::BACKOFFICE;

        // Same API channel (e.g. backoffice → HR): keep the token, only update workspace.
        if ($currentChannel === $loginChannel && $currentToken instanceof PersonalAccessToken) {
            if ($activeWorkspaceId !== null) {
                $currentToken->forceFill(['active_workspace_id' => $activeWorkspaceId])->save();
            }

            $memberships = $this->resolver->membershipsForCanonicalUser($account->canonicalUserId());
            $effective = $account->effectiveUser();

            return $this->attachPasswordExpiry([
                'token' => null,
                'user' => $effective,
                'organization' => $account->organization,
                'memberships' => $memberships,
                'must_change_password' => (bool) $effective->must_change_password,
            ], $effective);
        }

        $currentUser->currentAccessToken()?->delete();

        return $this->issueSession(
            $account,
            $clientId,
            forceLogout: false,
            loginChannel: $loginChannel,
            activeWorkspaceId: $activeWorkspaceId,
            skipSingleSessionCheck: true,
        );
    }

    /**
     * Start a till session for another operator after a verified PIN switch.
     *
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function issueOperatorSession(
        User $target,
        string $clientId,
        string $loginChannel,
        ?string $activeWorkspaceId = null,
    ): array {
        $org = \App\Models\Organization::findOrFail($target->organization_id);
        $account = $this->resolver->resolveForCanonicalUser($org, (int) $target->id);
        if (! $account) {
            throw ValidationException::withMessages([
                'user_id' => ['Unable to open a session for this user.'],
            ]);
        }

        return $this->issueSession(
            $account,
            $clientId,
            forceLogout: false,
            loginChannel: $loginChannel,
            activeWorkspaceId: $activeWorkspaceId,
            skipSingleSessionCheck: true,
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
        bool $skipSingleSessionCheck = false,
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
            \App\Support\LocalAgentTokens::excludeFromQuery($authUser->tokens())->delete();
        } else {
            $this->pruneStaleTokens($authUser);
            $authUser->tokens()->where('name', $clientId)->delete();
            $this->revokeAbandonedTokensElsewhere($authUser, $clientId);
            if (! $skipSingleSessionCheck) {
                $this->assertNoActiveSessionElsewhere($authUser, $clientId, $loginChannel);
            }
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
            // Mark issued immediately so exclusivity does not depend on a follow-up API hit.
            'last_used_at' => now(),
        ])->save();

        $memberships = $this->resolver->membershipsForCanonicalUser($account->canonicalUserId());

        $authUser->refresh();
        $effective = $account->effectiveUser();

        return $this->attachPasswordExpiry([
            'token' => $newToken->plainTextToken,
            'user' => $effective,
            'organization' => $account->organization,
            'memberships' => $memberships,
            'must_change_password' => (bool) $effective->must_change_password,
            'login_channel' => $loginChannel,
        ], $effective);
    }

    /** @param  array<string, mixed>  $payload */
    protected function attachPasswordExpiry(array $payload, User $user): array
    {
        $payload['password_expiry'] = app(PasswordExpiryService::class)->statusForUser($user);

        return $payload;
    }

    protected function pruneStaleTokens(User $authUser): void
    {
        // Expired tokens are never valid sessions.
        $authUser->tokens()
            ->where('name', 'not like', \App\Support\AttendanceAgentToken::NAME_PREFIX.'%')
            ->where('name', 'not like', \App\Support\KraAgentToken::NAME_PREFIX.'%')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        // Failed login handoffs (issued, never authenticated) — short window only.
        // Do NOT prune merely-idle tokens here: idle ≠ free slot for another PC.
        // Server-side idle revoke (AUTH_SERVER_IDLE_REVOKE) handles that separately.
        $abandonedCutoff = now()->subMinutes(2);
        $authUser->tokens()
            ->where('name', 'not like', \App\Support\AttendanceAgentToken::NAME_PREFIX.'%')
            ->where('name', 'not like', \App\Support\KraAgentToken::NAME_PREFIX.'%')
            ->whereNull('last_used_at')
            ->where('created_at', '<', $abandonedCutoff)
            ->delete();

        if (config('security.revoke_idle_tokens', false)) {
            $idleMinutes = $this->resolveIdleMinutesForUser($authUser);
            $idleCutoff = now()->subMinutes($idleMinutes);
            $authUser->tokens()
                ->where('name', 'not like', \App\Support\AttendanceAgentToken::NAME_PREFIX.'%')
            ->where('name', 'not like', \App\Support\KraAgentToken::NAME_PREFIX.'%')
                ->where(function ($query) use ($idleCutoff) {
                    $query
                        ->where(function ($q) use ($idleCutoff) {
                            $q->whereNotNull('last_used_at')
                                ->where('last_used_at', '<', $idleCutoff);
                        })
                        ->orWhere(function ($q) use ($idleCutoff) {
                            $q->whereNull('last_used_at')
                                ->where('created_at', '<', $idleCutoff);
                        });
                })
                ->delete();
        }
    }

    protected function revokeAbandonedTokensElsewhere(User $authUser, string $clientId): void
    {
        $authUser->tokens()
            ->where('name', '!=', $clientId)
            ->where('name', 'not like', \App\Support\AttendanceAgentToken::NAME_PREFIX.'%')
            ->where('name', 'not like', \App\Support\KraAgentToken::NAME_PREFIX.'%')
            ->whereNull('last_used_at')
            ->where('created_at', '<', now()->subMinutes(2))
            ->delete();
    }

    /**
     * One interactive session per login_channel per user.
     * ERP web (backoffice) on PC-A blocks ERP web on PC-B until logout or force_logout.
     * Manager / mobile / POS use other channels and may coexist with ERP.
     * Exclusivity follows token lifetime (expires_at), not screen-idle — an open but
     * idle ERP tab still owns the channel.
     */
    protected function assertNoActiveSessionElsewhere(
        User $authUser,
        string $clientId,
        string $loginChannel,
    ): void {
        $activeTokenExists = $authUser->tokens()
            ->where('name', '!=', $clientId)
            ->where('login_channel', $loginChannel)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
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
        if ($loginChannel === UserLoginChannelService::MOBILE) {
            app(UserMobileLoginValidator::class)->assertCanLoginViaMobile($user);

            return;
        }

        if ($loginChannel === UserLoginChannelService::MANAGER) {
            app(UserManagerLoginValidator::class)->assertCanLoginViaManager($user);

            return;
        }

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
        if (! $organization) {
            return;
        }

        $gate = (new CapabilityGate)->forOrganization($organization);
        $allowed = array_flip($gate->allowedLoginChannels());
        if (isset($allowed[$loginChannel])) {
            return;
        }

        $label = app(UserLoginChannelService::class)->label($loginChannel);
        $message = match ($loginChannel) {
            UserLoginChannelService::POS => 'External POS is not enabled for this organization.',
            UserLoginChannelService::MOBILE => 'Mobile app access is not enabled for this organization.',
            UserLoginChannelService::MANAGER => 'Centrix Manager app access is not enabled for this organization.',
            UserLoginChannelService::BACKOFFICE => 'Web ERP access is not enabled for this organization.',
            default => sprintf('%s is not enabled for this organization.', $label),
        };

        throw ValidationException::withMessages([
            'login_channel' => [$message],
        ]);
    }

    protected function resolveIdleMinutesForUser(User $authUser): int
    {
        $orgId = (int) ($authUser->tokens()->value('organization_id') ?? 0);

        return SecuritySettingsResolver::sessionIdleMinutesForOrganizationId($orgId ?: null);
    }
}
