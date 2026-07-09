<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Fulfillment\RouteAccessService;
use Illuminate\Support\Collection;
use App\Services\Auth\UserLoginChannelService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Mobile field sales are route-based: reps manage route customers and route orders.
 * An optional assigned route locks the rep to a single route; otherwise they pick a route in the app.
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

    /** @return array<string, mixed> */
    public function mobileContext(User $user): array
    {
        return [
            'mobile_order_scope' => $this->scope($user),
            'assigned_route_id' => $user->assigned_route_id ? (int) $user->assigned_route_id : null,
            'allowed_customer_types' => $this->allowedCustomerTypes($user),
            'can_use_all_channels' => false,
            'route_selection_locked' => $this->isRouteSelectionLocked($user),
        ];
    }

    public function isRouteSelectionLocked(User $user): bool
    {
        return $this->hasMobileChannel($user) && (bool) $user->assigned_route_id;
    }

    /** @return Collection<int, RouteModel> */
    public function listRoutesForUser(User $user): Collection
    {
        $query = RouteModel::query()
            ->where('is_active', true)
            ->orderBy('route_name');

        $this->routes->scopeForUser($query, $user);

        if ($this->isRouteSelectionLocked($user)) {
            $query->where('id', (int) $user->assigned_route_id);
        }

        return $query->get();
    }

    /** @param  Builder<\App\Models\Sale>  $query */
    public function applySaleScope(Builder $query, User $user): void
    {
        if (! $this->hasMobileChannel($user)) {
            return;
        }

        $query->whereNotNull('route_id');
        $this->access->scopeOrganization($query, $user, 'sales.organization_id');
        $this->access->scopeBranchIfLimited($query, $user, 'sales.branch_id');

        if ($user->assigned_route_id) {
            $query->where('route_id', (int) $user->assigned_route_id);
        }
    }

    /** @param  Builder<Customer>  $query */
    public function applyCustomerScope(Builder $query, User $user, ?int $routeId = null): void
    {
        if (! $this->hasMobileChannel($user)) {
            return;
        }

        $query->where('customers.customer_type', 'route');
        $this->access->scopeOrganization($query, $user, 'customers.organization_id');
        $this->access->scopeBranchIfLimited($query, $user, 'customers.branch_id');

        if ($user->assigned_route_id) {
            $query->where('customers.route_id', (int) $user->assigned_route_id);

            return;
        }

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
        if (! $this->hasMobileChannel($user)) {
            return $requestedRouteId;
        }

        if ($user->assigned_route_id) {
            return (int) $user->assigned_route_id;
        }

        if ($requestedRouteId !== null && $requestedRouteId > 0) {
            return (int) $requestedRouteId;
        }

        return null;
    }

    public function assertCartRouteId(User $user, ?int $routeId): void
    {
        if (! $this->hasMobileChannel($user)) {
            return;
        }

        if (! $routeId) {
            throw new InvalidArgumentException('A route is required for mobile sales.');
        }

        if (
            $user->assigned_route_id
            && (int) $routeId !== (int) $user->assigned_route_id
        ) {
            throw new InvalidArgumentException('This user is assigned to a different route.');
        }

        $this->routes->assertAccessible($user, (int) $routeId);
    }

    /** @param  array<string, mixed>  $payload */
    public function assertCustomerPayload(User $user, array $payload, ?Customer $existing = null): void
    {
        if (! $this->hasMobileChannel($user)) {
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
        if (! $routeId) {
            throw ValidationException::withMessages([
                'route_id' => ['Route is required for route customers.'],
            ]);
        }

        if (
            $user->assigned_route_id
            && (int) $routeId !== (int) $user->assigned_route_id
        ) {
            throw ValidationException::withMessages([
                'route_id' => ['This user can only manage customers on their assigned route.'],
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

        if (! $routeId) {
            throw new InvalidArgumentException('Select a route before completing this order.');
        }

        if (
            $user->assigned_route_id
            && (int) $routeId !== (int) $user->assigned_route_id
        ) {
            throw new InvalidArgumentException('This user is assigned to a different route.');
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

            return $data;
        }

        if ($hasMobile === true) {
            $data['mobile_order_scope'] = self::ROUTE_ONLY;
        }

        return $data;
    }
}
