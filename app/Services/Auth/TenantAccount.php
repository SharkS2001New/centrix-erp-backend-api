<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserMembership;

/**
 * Resolved login account for one organization — either a primary user row or a membership.
 */
class TenantAccount
{
    public function __construct(
        public User $authUser,
        public Organization $organization,
        public ?UserMembership $membership = null,
    ) {}

    public function effectiveUser(): User
    {
        if (! $this->membership) {
            return $this->authUser;
        }

        $virtual = $this->authUser->replicate();
        $virtual->id = $this->authUser->id;
        $virtual->organization_id = $this->membership->organization_id;
        $virtual->branch_id = $this->membership->branch_id;
        $virtual->role_id = $this->membership->role_id;
        $virtual->username = $this->membership->username;
        $virtual->is_admin = $this->membership->is_admin;
        $virtual->access_scope = $this->membership->access_scope;
        $virtual->is_active = $this->membership->is_active;

        return $virtual;
    }

    public function canonicalUserId(): int
    {
        return (int) $this->authUser->id;
    }
}
