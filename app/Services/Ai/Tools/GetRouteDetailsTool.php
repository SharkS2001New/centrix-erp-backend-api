<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\AiNearMissHelper;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Route lookup: assigned users/drivers, customers, recent order activity.
 */
class GetRouteDetailsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_route_details';
    }

    public function description(): string
    {
        return 'Look up a Centrix sales/distribution route by name or id: assigned users (who operates it), '
            .'default drivers, customer count, route_markup_price (KES), and recent order totals. '
            .'Use for "who operates route X?", "tell me about route 118", or one-route territory questions. '
            .'For "markup for each route" / list all route markups, use get_route_markups instead. '
            .'Never invent assignments or markup — always call a tool.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route_name' => [
                    'type' => 'string',
                    'description' => 'Route name or partial name (e.g. Nairobi East, 118).',
                ],
                'route_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric route id when known.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Fallback search text for the route.',
                ],
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days of recent order activity to include (1–90). Default 30.',
                ],
            ],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }
        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'fulfillment.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'admin.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view route data.',
                'screens' => $this->screens(),
            ];
        }

        $orgId = (int) $organization->id;
        $routeId = (int) ($arguments['route_id'] ?? 0);
        $needle = trim((string) ($arguments['route_name'] ?? $arguments['query'] ?? ''));
        $lookback = max(1, min(90, (int) ($arguments['lookback_days'] ?? 30)));

        $route = null;
        if ($routeId > 0) {
            $route = RouteModel::query()
                ->where('id', $routeId)
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                })
                ->first();
            if (! $route) {
                return [
                    'error' => true,
                    'message' => 'No route found for that id in this organization.',
                    'screens' => $this->screens(),
                ];
            }
        } elseif ($needle !== '') {
            $resolved = $this->resolveRoute($orgId, $needle);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, ['screens' => $this->screens()]);
            }
            $route = $resolved['route'];
        } else {
            return [
                'error' => true,
                'message' => 'Provide route_name, query, or route_id.',
                'screens' => $this->screens(),
            ];
        }

        return [
            'route' => $this->presentRoute($route, $orgId, $lookback),
            'screens' => $this->screens(),
            'tip' => 'List assigned_users (full_name + username) as who operates this route. '
                .'Include drivers and recent_orders summary when useful. Never invent operators.',
        ];
    }

    /**
     * @return array{error?: bool, route?: RouteModel, message?: string, candidates?: list<array<string, mixed>>}
     */
    protected function resolveRoute(int $organizationId, string $name): array
    {
        $needle = mb_strtolower(trim($name));
        $query = RouteModel::query()
            ->where(function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)->orWhereNull('organization_id');
            });

        $matches = (clone $query)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(route_name) LIKE ?', ['%'.$needle.'%']);
                if (ctype_digit($needle)) {
                    $q->orWhere('id', (int) $needle);
                }
            })
            ->orderBy('route_name')
            ->limit(10)
            ->get();

        if ($matches->isEmpty()) {
            $tokens = AiNearMissHelper::tokens($name);
            if ($tokens !== []) {
                $matches = (clone $query)
                    ->where(function ($q) use ($tokens) {
                        foreach ($tokens as $token) {
                            $q->orWhereRaw('LOWER(route_name) LIKE ?', ['%'.mb_strtolower($token).'%']);
                        }
                    })
                    ->orderBy('route_name')
                    ->limit(10)
                    ->get();
            }
        }

        if ($matches->isEmpty()) {
            return AiNearMissHelper::noExact(
                $name,
                null,
                [],
                $this->screens(),
                'Open Routes to verify the route name.',
            );
        }

        if ($matches->count() === 1) {
            return ['route' => $matches->first()];
        }

        return AiNearMissHelper::ambiguous(
            $name,
            $matches->map(fn (RouteModel $row) => [
                'label' => (string) $row->route_name,
                'route_id' => (int) $row->id,
                'is_active' => (bool) ($row->is_active ?? true),
            ])->all(),
            'route',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRoute(RouteModel $route, int $orgId, int $lookbackDays): array
    {
        $routeId = (int) $route->id;
        $assignedUsers = [];

        if (Schema::hasTable('user_assigned_routes')) {
            $assignedUsers = User::query()
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($routeId) {
                    $q->whereHas('assignedRoutes', fn ($r) => $r->where('routes.id', $routeId))
                        ->orWhere('assigned_route_id', $routeId);
                })
                ->with(['role:id,role_name'])
                ->orderBy('full_name')
                ->limit(50)
                ->get()
                ->map(fn (User $u) => [
                    'username' => (string) $u->username,
                    'full_name' => (string) ($u->full_name ?: $u->username),
                    'role' => $u->role?->role_name,
                    'is_active' => (bool) $u->is_active,
                ])
                ->values()
                ->all();
        }

        $drivers = [];
        if (Schema::hasTable('drivers')) {
            $drivers = Driver::query()
                ->where('organization_id', $orgId)
                ->where('default_route_id', $routeId)
                ->with(['user:id,username'])
                ->orderBy('full_name')
                ->limit(20)
                ->get(['id', 'driver_code', 'full_name', 'phone', 'is_active', 'user_id'])
                ->map(fn (Driver $d) => [
                    'driver_code' => $d->driver_code ? (string) $d->driver_code : null,
                    'full_name' => (string) ($d->full_name ?? ''),
                    'phone' => $d->phone ? (string) $d->phone : null,
                    'is_active' => (bool) $d->is_active,
                    'username' => $d->user?->username,
                ])
                ->values()
                ->all();
        }

        $customerCount = 0;
        if (Schema::hasTable('customers')) {
            $customerCount = (int) Customer::query()
                ->where('organization_id', $orgId)
                ->where('route_id', $routeId)
                ->whereNull('deleted_at')
                ->count();
        }

        $from = now()->subDays($lookbackDays)->toDateString();
        $orders = [
            'lookback_days' => $lookbackDays,
            'from_date' => $from,
            'order_count' => 0,
            'order_total' => 0.0,
            'unpaid_count' => 0,
            'unpaid_total' => 0.0,
        ];
        if (Schema::hasTable('sales')) {
            $base = Sale::query()
                ->where('organization_id', $orgId)
                ->where('route_id', $routeId)
                ->whereDate(DB::raw('COALESCE(delivery_date, created_at)'), '>=', $from)
                ->where('status', '!=', 'cancelled');

            $orders['order_count'] = (int) (clone $base)->count();
            $orders['order_total'] = round((float) (clone $base)->sum('order_total'), 2);
            $unpaid = (clone $base)->where(function ($q) {
                $q->whereIn('payment_status', ['unpaid', 'partial'])
                    ->orWhere(function ($inner) {
                        $inner->whereNull('payment_status')
                            ->whereColumn('amount_paid', '<', 'order_total');
                    });
            });
            $orders['unpaid_count'] = (int) (clone $unpaid)->count();
            $orders['unpaid_total'] = round((float) (clone $unpaid)->sum(DB::raw('GREATEST(order_total - COALESCE(amount_paid, 0), 0)')), 2);
        }

        return [
            'id' => $routeId,
            'route_name' => (string) ($route->route_name ?? ''),
            'direction' => $route->direction ? (string) $route->direction : null,
            'is_active' => (bool) ($route->is_active ?? true),
            'route_markup_price' => round((float) ($route->route_markup_price ?? 0), 2),
            'branch_id' => $route->branch_id !== null ? (int) $route->branch_id : null,
            'assigned_users' => $assignedUsers,
            'assigned_user_count' => count($assignedUsers),
            'drivers' => $drivers,
            'customer_count' => $customerCount,
            'recent_orders' => $orders,
        ];
    }

    /** @return list<array{label: string, path: string}> */
    protected function screens(): array
    {
        return [
            ['label' => 'Routes', 'path' => '/fulfillment/routes'],
            ['label' => 'Drivers', 'path' => '/fulfillment/drivers'],
            ['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile'],
            ['label' => 'Trips', 'path' => '/fulfillment/trips'],
        ];
    }
}
