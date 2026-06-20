<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Erp\WorkspaceSessionLabel;
use Illuminate\Support\Carbon;

class PlatformActiveSessionService
{
    /**
     * @return list<array{organization: array<string, mixed>, sessions: list<array<string, mixed>>}>
     */
    public function groupedActiveSessions(): array
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');

        $organizations = Organization::query()
            ->where('company_code', '!=', $platformCode)
            ->orderBy('org_name')
            ->get(['id', 'company_code', 'org_name']);

        if ($organizations->isEmpty()) {
            return [];
        }

        $idleByOrg = $organizations->mapWithKeys(
            fn (Organization $org) => [
                $org->id => SecuritySettingsResolver::sessionIdleMinutesForOrganizationId((int) $org->id),
            ],
        );

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        if ($tokens->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $tokens->pluck('tokenable_id')->unique())
            ->where('is_super_admin', false)
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

            $idleMinutes = (int) ($idleByOrg->get($orgId) ?? config('erp.session_idle_minutes', 15));
            if (! $this->isTokenActive($token, $idleMinutes)) {
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
                'login_channel' => $token->login_channel ?: UserLoginChannelService::BACKOFFICE,
                'active_workspace_id' => $token->active_workspace_id,
                'active_workspace_label' => WorkspaceSessionLabel::for(
                    $token->active_workspace_id,
                    $token->login_channel ?: UserLoginChannelService::BACKOFFICE,
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
