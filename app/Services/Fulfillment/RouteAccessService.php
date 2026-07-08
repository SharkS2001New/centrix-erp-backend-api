<?php

namespace App\Services\Fulfillment;

use App\Models\RouteModel;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Support\TenantRouteRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Routes are organization master data: one catalog per tenant, shared across branches.
 * Branch context applies on customers, sales, schedules, and trips — not on the route row itself.
 */
class RouteAccessService
{
    public function __construct(protected UserAccessService $access) {}

    public function organizationId(?User $user, ?Request $request = null): ?int
    {
        return $user ? $this->access->organizationId($user, $request) : null;
    }

    /** @param  Builder<RouteModel>  $query */
    public function scopeOrganization(
        Builder $query,
        User $user,
        ?Request $request = null,
        string $column = 'organization_id',
    ): Builder {
        return $this->access->scopeOrganization($query, $user, $column, $request);
    }

    public function scopeOrganizationId(Builder $query, int $organizationId, string $column = 'organization_id'): Builder
    {
        return $query->where($column, $organizationId);
    }

    public function findForOrganization(int $organizationId, int $routeId): ?RouteModel
    {
        return RouteModel::query()
            ->where('organization_id', $organizationId)
            ->where('id', $routeId)
            ->first();
    }

    public function findForUser(User $user, int $routeId, ?Request $request = null): ?RouteModel
    {
        $orgId = $this->organizationId($user, $request);
        if (! $orgId) {
            return RouteModel::query()->find($routeId);
        }

        return $this->findForOrganization($orgId, $routeId);
    }

    /** @return Collection<int, RouteModel> */
    public function listActiveForUser(User $user, ?Request $request = null): Collection
    {
        $query = RouteModel::query()
            ->where('is_active', true)
            ->orderBy('route_name');

        $this->scopeOrganization($query, $user, $request);

        return $query->get();
    }

    public function assertAccessible(
        User $user,
        int $routeId,
        ?string $field = null,
        ?Request $request = null,
    ): void {
        $orgId = $this->organizationId($user, $request);
        if (! $orgId) {
            return;
        }

        if ($this->findForOrganization($orgId, $routeId)) {
            return;
        }

        $message = 'The selected route is not available for this organization.';
        if ($field) {
            throw ValidationException::withMessages([$field => [$message]]);
        }

        throw new InvalidArgumentException($message);
    }

    /** @return array<int, mixed> */
    public function validationNullable(?User $user, ?Request $request = null): array
    {
        return TenantRouteRules::nullable($this->organizationId($user, $request));
    }

    /** @return array<int, mixed> */
    public function validationRequired(?User $user, ?Request $request = null): array
    {
        return TenantRouteRules::required($this->organizationId($user, $request));
    }

    /** @return array<int, mixed> */
    public function validationEach(?User $user, ?Request $request = null): array
    {
        return TenantRouteRules::each($this->organizationId($user, $request));
    }
}
