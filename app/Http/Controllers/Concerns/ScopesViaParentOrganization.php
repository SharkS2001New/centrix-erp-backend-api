<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Tenant scope for child tables that inherit organization from a parent relation.
 *
 * Controllers must implement parentOrganizationScope(): array{
 *   relation: string,
 *   column?: string,
 * }
 */
trait ScopesViaParentOrganization
{
    /** @return array{relation: string, column?: string} */
    abstract protected function parentOrganizationScope(): array;

    protected function baseQuery(Request $request)
    {
        $query = ($this->modelClass())::query();
        $user = $request->user();
        if (! $user) {
            return $query;
        }

        $scope = $this->parentOrganizationScope();
        $orgId = $this->access()->organizationId($user, $request);
        if ($orgId) {
            $relation = $scope['relation'];
            if ($scope['via_branch'] ?? false) {
                $query->whereHas($relation, function (Builder $parent) use ($orgId) {
                    $parent->whereIn('branch_id', function ($sub) use ($orgId) {
                        $sub->select('id')
                            ->from('branches')
                            ->where('organization_id', $orgId);
                    });
                });
            } else {
                $column = $scope['column'] ?? 'organization_id';
                $query->whereHas($relation, fn (Builder $parent) => $parent->where($column, $orgId));
            }
        }

        return $query;
    }
}
