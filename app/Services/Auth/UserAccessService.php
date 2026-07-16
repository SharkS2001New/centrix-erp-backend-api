<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

    public function organizationId(User $user, ?Request $request = null): ?int
    {
        if ($actingId = $request?->attributes->get('acting_organization_id')) {
            return (int) $actingId;
        }

        return $user->organization_id ? (int) $user->organization_id : null;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function scopeOrganizationViaBranch(
        Builder $query,
        User $user,
        string $branchColumn = 'branch_id',
        ?Request $request = null,
    ): Builder {
        $orgId = $this->organizationId($user, $request);
        if (! $orgId) {
            return $query;
        }

        return $query->whereIn($branchColumn, function ($sub) use ($orgId) {
            $sub->select('id')
                ->from('branches')
                ->where('organization_id', $orgId);
        });
    }

    public function assertBranchInOrganization(
        User $user,
        int $branchId,
        ?Request $request = null,
        string $message = 'You do not have access to this branch.',
    ): void {
        $orgId = $this->organizationId($user, $request);
        if (! $orgId) {
            return;
        }

        $exists = \App\Models\Branch::query()
            ->where('id', $branchId)
            ->where('organization_id', $orgId)
            ->exists();

        if (! $exists) {
            abort(403, $message);
        }
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function scopeOrganization(
        Builder $query,
        User $user,
        string $column = 'organization_id',
        ?Request $request = null,
    ): Builder {
        $orgId = $this->organizationId($user, $request);
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
     * Branch-limited users: forced to their branch.
     * Org-wide users: optional filter via filter[branch_id] or branch_id (must belong to org).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function applyBranchListFilter(
        Builder $query,
        User $user,
        ?Request $request = null,
        string $column = 'branch_id',
    ): Builder {
        $this->scopeBranchIfLimited($query, $user, $column);

        $request ??= request();
        if ($this->branchId($user) !== null) {
            return $query;
        }

        $raw = $request->input('filter.branch_id', $request->input('branch_id'));
        if ($raw === null || $raw === '') {
            return $query;
        }

        $branchId = (int) $raw;
        $this->assertBranchInOrganization($user, $branchId, $request);
        $query->where($column, $branchId);

        return $query;
    }

    public function assertBranchAccess(User $user, ?int $branchId, string $message = 'You do not have access to this branch.'): void
    {
        if ($branchId === null) {
            return;
        }

        $limitedBranch = $this->branchId($user);
        if ($limitedBranch !== null && $limitedBranch !== $branchId) {
            abort(403, $message);
        }
    }

    public function resolveBranchId(User $user, ?int $requestedBranchId = null): int
    {
        $limitedBranch = $this->branchId($user);
        if ($limitedBranch !== null) {
            if ($requestedBranchId !== null && (int) $requestedBranchId !== $limitedBranch) {
                abort(403, 'You can only operate within your assigned branch.');
            }

            return $limitedBranch;
        }

        $branchId = $requestedBranchId ?? $user->branch_id;
        if (! $branchId) {
            abort(422, 'Branch is required.');
        }

        return (int) $branchId;
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
