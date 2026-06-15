<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class UserAccessService
{
    public function isOrgWide(User $user): bool
    {
        return ($user->access_scope ?? 'org') === 'org' || (bool) $user->is_admin;
    }

    public function branchId(User $user): ?int
    {
        if ($this->isOrgWide($user)) {
            return null;
        }

        return $user->branch_id ? (int) $user->branch_id : null;
    }

    public function organizationId(User $user): ?int
    {
        return $user->organization_id ? (int) $user->organization_id : null;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function scopeOrganization(Builder $query, User $user, string $column = 'organization_id'): Builder
    {
        $orgId = $this->organizationId($user);
        if ($orgId) {
            $query->where($column, $orgId);
        }

        return $query;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function scopeBranchIfLimited(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        $branchId = $this->branchId($user);
        if ($branchId !== null) {
            $query->where($column, $branchId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validateAccessScope(array $data, bool $isAdmin = false): array
    {
        $accessScope = $data['access_scope'] ?? ($isAdmin ? 'org' : 'branch');
        if (! in_array($accessScope, ['org', 'branch'], true)) {
            throw ValidationException::withMessages([
                'access_scope' => ['Access scope must be org or branch.'],
            ]);
        }

        if ($accessScope === 'branch' && empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => ['Branch is required for branch-limited users.'],
            ]);
        }

        $data['access_scope'] = $accessScope;

        return $data;
    }
}
