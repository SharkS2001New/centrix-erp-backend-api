<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** Skip last_used_at writes more often than once per minute (idle timeout stays accurate). */
    public const LAST_USED_THROTTLE_SECONDS = 60;

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'organization_id',
        'user_membership_id',
        'login_channel',
        'active_workspace_id',
        'last_used_at',
    ];

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function save(array $options = [])
    {
        if ($this->isDirty('last_used_at') && count($this->getDirty()) === 1) {
            $previous = $this->getOriginal('last_used_at');
            if ($previous) {
                try {
                    $previousAt = $this->asDateTime($previous);
                    if ($previousAt->gt(now()->subSeconds(self::LAST_USED_THROTTLE_SECONDS))) {
                        $this->syncOriginal();

                        return true;
                    }
                } catch (\Throwable) {
                    // Fall through to normal save.
                }
            }
        }

        return parent::save($options);
    }
}
