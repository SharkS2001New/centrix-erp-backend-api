<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Erp\IndustryRegistry;
use App\Services\Erp\WorkspaceSessionLabel;
use App\Support\CentrixAgentServiceUser;
use App\Support\LocalAgentTokens;
use Illuminate\Support\Carbon;

/**
 * Active Sanctum sessions for the platform Active users page.
 *
 * Presence uses personal_access_tokens.last_used_at (fallback: created_at).
 * Local agent tokens (KRA / attendance) and Centrix agent machine users are
 * excluded — long-lived heartbeats, not human sessions.
 *
 * Mobile / manager channels use a dedicated presence window (default 120
 * minutes, never looser than the org session idle) so idle phone sessions
 * drop off like ERP rather than lingering for hours.
 */
class PlatformActiveSessionService
{
    /**
     * @return list<array{organization: array<string, mixed>, sessions: list<array<string, mixed>>}>
     */
    public function groupedActiveSessions(): array
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');
        $mobilePresenceCap = max(1, (int) config('erp.mobile_presence_minutes', 120));

        $organizations = Organization::query()
            ->where('company_code', '!=', $platformCode)
            ->orderBy('org_name')
            ->get(['id', 'company_code', 'org_name', 'deployment_profile']);

        if ($organizations->isEmpty()) {
            return [];
        }

        $industryByOrg = $organizations->mapWithKeys(
            fn (Organization $org) => [
                $org->id => IndustryRegistry::industryForProfile((string) ($org->deployment_profile ?? 'wholesale_retail')),
            ],
        );

        $idleByOrg = $organizations->mapWithKeys(
            fn (Organization $org) => [
                $org->id => SecuritySettingsResolver::sessionIdleMinutesForOrganizationId((int) $org->id),
            ],
        );

        $maxIdleMinutes = max(1, (int) $idleByOrg->max(), $mobilePresenceCap);
        $activityCutoff = now()->subMinutes($maxIdleMinutes);

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($activityCutoff) {
                $query->where('last_used_at', '>=', $activityCutoff)
                    ->orWhere(function ($q) use ($activityCutoff) {
                        $q->whereNull('last_used_at')
                            ->where('created_at', '>=', $activityCutoff);
                    });
            })
            ->tap(fn ($q) => LocalAgentTokens::excludeFromQuery($q))
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        if ($tokens->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $tokens->pluck('tokenable_id')->unique())
            ->where('is_super_admin', false)
            ->tap(fn ($q) => CentrixAgentServiceUser::excludeFromQuery($q, 'username'))
            ->get()
            ->keyBy('id');

        $byOrg = [];
        foreach ($organizations as $org) {
            $byOrg[$org->id] = [
                'organization' => $org->only(['id', 'company_code', 'org_name']),
                'sessions' => [],
            ];
        }

        foreach ($tokens as $token) {
            $orgId = (int) $token->organization_id;
            if (! isset($byOrg[$orgId])) {
                continue;
            }

            $channel = is_string($token->login_channel) && $token->login_channel !== ''
                ? strtolower($token->login_channel)
                : UserLoginChannelService::BACKOFFICE;

            $orgIdleMinutes = (int) ($idleByOrg->get($orgId) ?? config('erp.session_idle_minutes', 15));
            $presenceMinutes = $this->presenceMinutesForChannel($channel, $orgIdleMinutes, $mobilePresenceCap);

            if (! $this->isTokenActive($token, $presenceMinutes)) {
                continue;
            }

            $user = $users->get($token->tokenable_id);
            if (! $user) {
                continue;
            }

            $byOrg[$orgId]['sessions'][] = [
                'id' => $token->id,
                'user_id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'login_channel' => $channel,
                'active_workspace_id' => $token->active_workspace_id,
                'active_workspace_label' => WorkspaceSessionLabel::for(
                    $token->active_workspace_id,
                    $channel,
                    $industryByOrg->get($orgId),
                ),
                'computer_id' => $token->name,
                'last_active_at' => ($token->last_used_at ?? $token->updated_at)?->toIso8601String(),
                'session_started_at' => $token->created_at?->toIso8601String(),
                'is_user_active' => (bool) $user->is_active,
            ];
        }

        return array_values(array_filter(
            $byOrg,
            fn (array $group) => $group['sessions'] !== [],
        ));
    }

    public function presenceMinutesForChannel(
        string $channel,
        int $orgIdleMinutes,
        ?int $mobilePresenceCap = null,
    ): int {
        $orgIdleMinutes = max(1, $orgIdleMinutes);
        $mobilePresenceCap = max(1, $mobilePresenceCap ?? (int) config('erp.mobile_presence_minutes', 120));

        if ($channel === UserLoginChannelService::MOBILE
            || $channel === UserLoginChannelService::MANAGER) {
            return min($orgIdleMinutes, $mobilePresenceCap);
        }

        return $orgIdleMinutes;
    }

    public function isTokenActive(PersonalAccessToken $token, int $idleMinutes): bool
    {
        $idleMinutes = max(1, $idleMinutes);
        $cutoff = now()->subMinutes($idleMinutes);

        $lastActivity = $token->last_used_at ?? $token->created_at;
        if (! $lastActivity instanceof Carbon) {
            return false;
        }

        return $lastActivity->gte($cutoff);
    }

    public function findTenantSession(int $tokenId): PersonalAccessToken
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');
        $tenantOrgIds = Organization::query()
            ->where('company_code', '!=', $platformCode)
            ->pluck('id');

        return PersonalAccessToken::query()
            ->whereKey($tokenId)
            ->where('tokenable_type', User::class)
            ->whereIn('organization_id', $tenantOrgIds)
            ->firstOrFail();
    }

    public function revokeSession(int $tokenId): void
    {
        $this->findTenantSession($tokenId)->delete();
    }

    public function disableUserForSession(int $tokenId): User
    {
        $token = $this->findTenantSession($tokenId);
        $user = User::query()->findOrFail($token->tokenable_id);

        return app(UserLoginService::class)->disableLogin($user);
    }
}
