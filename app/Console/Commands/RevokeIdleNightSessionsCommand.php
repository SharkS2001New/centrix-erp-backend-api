<?php

namespace App\Console\Commands;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\NightSessionPolicy;
use App\Support\CentrixAgentServiceUser;
use App\Support\LocalAgentTokens;
use Illuminate\Console\Command;

class RevokeIdleNightSessionsCommand extends Command
{
    protected $signature = 'erp:revoke-idle-night-sessions';

    protected $description = 'Revoke interactive login sessions idle past the overnight limit.';

    public function handle(NightSessionPolicy $policy): int
    {
        if (! $policy->isActive()) {
            $this->info('Night session window is not active.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMinutes($policy->idleMinutes());
        $serviceUsers = User::query();
        foreach (CentrixAgentServiceUser::USERNAME_PREFIXES as $prefix) {
            $serviceUsers->orWhere('username', 'like', $prefix.'%');
        }

        $revoked = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereNotIn('tokenable_id', $serviceUsers->select('id'))
            ->where(function ($query) use ($cutoff) {
                $query->where('last_used_at', '<', $cutoff)
                    ->orWhere(function ($fallback) use ($cutoff) {
                        $fallback->whereNull('last_used_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->tap(fn ($query) => LocalAgentTokens::excludeFromQuery($query))
            ->delete();

        $this->info("Revoked {$revoked} idle night session(s).");

        return self::SUCCESS;
    }
}
