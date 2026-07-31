<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Fulfillment\RouteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Mobile field sales are route-based: reps manage route customers and route orders.
 * Optional assigned route(s) lock the rep to one or more routes; otherwise they pick any accessible route.
 */
class UserMobileOrderScopeService
{
    public function __construct(
        protected UserAccessService $access,
        protected RouteAccessService $routes,
    ) {}

    public const ROUTE_ONLY = 'route_only';

    /** @deprecated Kept for DB compatibility; mobile users are always route-only. */
    public const NORMAL_ONLY = 'normal_only';

    /** @deprecated Kept for DB compatibility; mobile users are always route-only. */
    public const BOTH = 'both';

    /** @return list<string> */
    public static function scopes(): array
    {
        return [self::ROUTE_ONLY];
    }

    public function hasMobileChannel(User $user): bool
    {
        $channels = app(UserLoginChannelService::class)->normalize($user->login_channels);

        return in_array(UserLoginChannelService::MOBILE, $channels, true);
    }

    public function scope(User $user): string
    {
        if (! $this->hasMobileChannel($user)) {
            return self::BOTH;
        }

        return self::ROUTE_ONLY;
    }

    public function canUseAllChannels(User $user): bool
    {
        return false;
    }

    /** @return list<string> */
    public function allowedCustomerTypes(User $user): array
    {
        if (! $this->hasMobileChannel($user)) {
            return ['debtor', 'regular', 'route'];
        }

        return ['route'];
    }

    /**
     * Assigned route allowlist (pivot + legacy users.assigned_route_id fallback).
     *
     * @return list<int>
     */
    public function assignedRouteIds(User $user): array
    {
        $ids = [];
        if (Schema::hasTable('user_assigned_routes')) {
            if ($user->relationLoaded('assignedRoutes')) {
                $ids = $user->assignedRoutes
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } else {
                $ids = $user->assignedRoutes()
                    ->pluck('routes.id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        }

        if ($ids === [] && $user->assigned_route_id) {
            $ids = [(int) $user->assigned_route_id];
        }

        return array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
    }

    /**
     * Sync assigned routes and mirror the first id onto legacy assigned_route_id.
     *
     * @param  list<int|string>|null  $routeIds  null = leave unchanged; [] = unlock
     */
    public function syncAssignedRoutes(User $user, ?array $routeIds): void
    {
        if ($routeIds === null) {
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $routeIds),
            static fn (int $id) => $id > 0,
        )));

        foreach ($ids as $routeId) {
            $this->routes->assertAccessible($user, $routeId, 'assigned_route_ids');
        }

        if (Schema::hasTable('user_assigned_routes')) {
            $user->assignedRoutes()->sync($ids);
        }

        $user->forceFill([
            'assigned_route_id' => $ids[0] ?? null,
        ])->save();

        $user->unsetRelation('assignedRoutes');
    }

    /** @return array<string, mixed> */
    public function mobileContext(User $user): array
    {
        $assignedRouteIds = $this->assignedRouteIds($user);
        $assignedRouteId = $assignedRouteIds[0] ?? null;
        $assignedRouteName = null;
        $assignedRouteNames = [];

        if ($assignedRouteIds !== []) {
            $routes = RouteModel::query()
                ->whereIn('id', $assignedRouteIds)
                ->orderBy('route_name')
                ->get(['id', 'route_name']);
            $assignedRouteNames = $routes
                ->map(fn ($route) => (string) $route->route_name)
                ->values()
                ->all();
            if ($assignedRouteId) {
                $assignedRouteName = $routes->firstWhere('id', $assignedRouteId)?->route_name;
                $assignedRouteName = $assignedRouteName !== null ? (string) $assignedRouteName : null;
            }
        }

        return [
            'mobile_order_scope' => $this->scope($user),
            'assigned_route_id' => $assignedRouteId,
            'assigned_route_ids' => $assignedRouteIds,
            'assigned_route_name' => $assignedRouteName,
            'assigned_route_names' => $assignedRouteNames,
            'allowed_customer_types' => $this->allowedCustomerTypes($user),
            'can_use_all_channels' => false,
            'route_selection_locked' => $this->isRouteSelectionLocked($user),
        ];
    }

    public function isRouteSelectionLocked(User $user): bool
    {
        return $this->assignedRouteIds($user) !== [];
    }

    public function isRouteAllowed(User $user, int $routeId): bool
    {
        $ids = $this->assignedRouteIds($user);
        if ($ids === []) {
            return true;
        }

        return in_array((int) $routeId, $ids, true);
    }

    /** @return Collection<int, RouteModel> */
    public function listRoutesForUser(User $user): Collection
    {
        $query = RouteModel::query()
            ->where('is_active', true)
            ->orderBy('route_name');

        $this->routes->scopeForUser($query, $user);

        $assigned = $this->assignedRouteIds($user);
        if ($assigned !== []) {
            $query->whereIn('id', $assigned);
        }

        return $query->get();
    }

    /** @param  Builder<\App\Models\Sale>  $query */
    public function applySaleScope(Builder $query, User $user): void
    {
        $assigned = $this->assignedRouteIds($user);
        if ($assigned !== []) {
            $query->whereNotNull('route_id');
            $this->access->scopeOrganization($query, $user, 'sales.organization_id');
            $this->access->scopeBranchIfLimited($query, $user, 'sales.branch_id');
            $query->whereIn('route_id', $assigned);

            return;
        }

        if (! $this->hasMobileChannel($user)) {
            return;
        }

        $query->whereNotNull('route_id');
        $this->access->scopeOrganization($query, $user, 'sales.organization_id');
        $this->access->scopeBranchIfLimited($query, $user, 'sales.branch_id');
    }

    /** @param  Builder<Customer>  $query */
    public function applyCustomerScope(Builder $query, User $user, ?int $routeId = null): void
    {
        $assigned = $this->assignedRouteIds($user);

        // Assigned routes are an absolute lock — always enforce, even if login_channels
        // are misconfigured. Mobile admins set this to restrict the rep to those routes.
        if ($assigned !== []) {
            $query->where('customers.customer_type', 'route');
            $this->access->scopeOrganization($query, $user, 'customers.organization_id');
            $this->access->scopeBranchIfLimited($query, $user, 'customers.branch_id');

            if ($routeId !== null && $routeId > 0 && in_array($routeId, $assigned, true)) {
                $query->where('customers.route_id', $routeId);
            } else {
                $query->whereIn('customers.route_id', $assigned);
            }

            return;
        }

        if (! $this->hasMobileChannel($user)) {
            return;
        }

        $query->where('customers.customer_type', 'route');
        $this->access->scopeOrganization($query, $user, 'customers.organization_id');
        $this->access->scopeBranchIfLimited($query, $user, 'customers.branch_id');

        if ($routeId !== null && $routeId > 0) {
            $query->where('customers.route_id', $routeId);
        }
    }

    public function findCheckoutCustomer(User $user, int $customerNum, string $channel = 'mobile'): Customer
    {
        $query = Customer::query()
            ->where('customer_num', $customerNum)
            ->whereNull('deleted_at');

        if ($channel === 'mobile' && $this->hasMobileChannel($user)) {
            $this->applyCustomerScope($query, $user);
            $customer = $query->first();
            if ($customer === null) {
                throw ValidationException::withMessages([
                    'customer_num' => [
                        'Customer not found or not available on your route and branch.',
                    ],
                ]);
            }

            return $customer;
        }

        $this->access->scopeOrganization($query, $user, 'customers.organization_id');
        $this->access->scopeBranchIfLimited($query, $user, 'customers.branch_id');

        return $query->firstOrFail();
    }

    public function resolveCartRouteId(User $user, ?int $requestedRouteId): ?int
    {
        $assigned = $this->assignedRouteIds($user);

        if ($assigned !== []) {
            if ($requestedRouteId !== null && $requestedRouteId > 0) {
                if (! in_array((int) $requestedRouteId, $assigned, true)) {
                    throw new InvalidArgumentException('This user is assigned to a different route.');
                }

                return (int) $requestedRouteId;
            }

            // Single locked route can be inferred; multi-route requires an explicit choice.
            return count($assigned) === 1 ? $assigned[0] : null;
        }

        if (! $this->hasMobileChannel($user)) {
            return $requestedRouteId;
        }

        if ($requestedRouteId !== null && $requestedRouteId > 0) {
            return (int) $requestedRouteId;
        }

        return null;
    }

    public function assertCartRouteId(User $user, ?int $routeId): void
    {
        $assigned = $this->assignedRouteIds($user);

        if ($assigned !== []) {
            if (! $routeId) {
                throw new InvalidArgumentException('A route is required for mobile sales.');
            }
            if (! in_array((int) $routeId, $assigned, true)) {
                throw new InvalidArgumentException('This user is assigned to a different route.');
            }
            $this->routes->assertAccessible($user, (int) $routeId);

            return;
        }

        if (! $this->hasMobileChannel($user)) {
            return;
        }

        if (! $routeId) {
            throw new InvalidArgumentException('A route is required for mobile sales.');
        }

        $this->routes->assertAccessible($user, (int) $routeId);
    }

    /** @param  array<string, mixed>  $payload */
    public function assertCustomerPayload(User $user, array $payload, ?Customer $existing = null): void
    {
        $assigned = $this->assignedRouteIds($user);

        if (! $this->hasMobileChannel($user) && $assigned === []) {
            return;
        }

        $type = (string) ($payload['customer_type'] ?? $existing?->customer_type ?? 'route');

        if ($type !== 'route') {
            throw ValidationException::withMessages([
                'customer_type' => [
                    'Mobile reps can only manage route customers.',
                ],
            ]);
        }

        $routeId = $payload['route_id'] ?? $existing?->route_id;
        if (! $routeId && count($assigned) === 1) {
            $routeId = $assigned[0];
        }
        if (! $routeId) {
            throw ValidationException::withMessages([
                'route_id' => ['Route is required for route customers.'],
            ]);
        }

        if ($assigned !== [] && ! in_array((int) $routeId, $assigned, true)) {
            throw ValidationException::withMessages([
                'route_id' => ['This user can only manage customers on their assigned routes.'],
            ]);
        }

        $this->routes->assertAccessible($user, (int) $routeId, 'route_id');

        $route = $this->routes->findForUser($user, (int) $routeId);
        $targetBranchId = (int) (
            $payload['branch_id']
            ?? $existing?->branch_id
            ?? $user->branch_id
            ?? 0
        );
        if (
            $route
            && $route->branch_id
            && $targetBranchId > 0
            && (int) $route->branch_id !== $targetBranchId
        ) {
            throw ValidationException::withMessages([
                'route_id' => ['The selected route belongs to a different branch.'],
            ]);
        }
    }

    public function assertCheckoutRoute(User $user, string $channel, ?int $routeId): void
    {
        if ($channel !== 'mobile') {
            return;
        }

        $assigned = $this->assignedRouteIds($user);

        if ($assigned !== []) {
            if (! $routeId) {
                throw new InvalidArgumentException('Select a route before completing this order.');
            }
            if (! in_array((int) $routeId, $assigned, true)) {
                throw new InvalidArgumentException('This user is assigned to a different route.');
            }
            $this->routes->assertAccessible($user, (int) $routeId);

            return;
        }

        if (! $routeId) {
            throw new InvalidArgumentException('Select a route before completing this order.');
        }

        $this->routes->assertAccessible($user, (int) $routeId);
    }

    /** @param  array<string, mixed>  $data */
    public function normalizeUserAttributes(array $data): array
    {
        $channels = array_key_exists('login_channels', $data)
            ? app(UserLoginChannelService::class)->normalize($data['login_channels'])
            : null;

        $hasMobile = $channels
            ? in_array(UserLoginChannelService::MOBILE, $channels, true)
            : null;

        if ($hasMobile === false) {
            $data['mobile_order_scope'] = null;
            $data['assigned_route_id'] = null;
            $data['assigned_route_ids'] = [];

            return $data;
        }

        if ($hasMobile === true) {
            $data['mobile_order_scope'] = self::ROUTE_ONLY;
        }

        if (array_key_exists('assigned_route_ids', $data) && is_array($data['assigned_route_ids'])) {
            $ids = array_values(array_unique(array_filter(
                array_map(static fn ($id) => (int) $id, $data['assigned_route_ids']),
                static fn (int $id) => $id > 0,
            )));
            $data['assigned_route_ids'] = $ids;
            $data['assigned_route_id'] = $ids[0] ?? null;
        } elseif (array_key_exists('assigned_route_id', $data)) {
            $id = $data['assigned_route_id'] !== null && $data['assigned_route_id'] !== ''
                ? (int) $data['assigned_route_id']
                : null;
            $data['assigned_route_id'] = $id && $id > 0 ? $id : null;
            if (! array_key_exists('assigned_route_ids', $data)) {
                $data['assigned_route_ids'] = $data['assigned_route_id']
                    ? [$data['assigned_route_id']]
                    : [];
            }
        }

        return $data;
    }
}
