<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Employee;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Ai\Tools\Concerns\ResolvesAiUsers;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * User profile + assigned routes for AI chat ("which routes does Chege operate?").
 */
class GetUserDetailsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;
    use ResolvesAiUsers;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_user_details';
    }

    public function description(): string
    {
        return 'Look up a Centrix user (salesperson / cashier / mobile rep): username, full name, role, branch, '
            .'login channels, and assigned sales routes (user_assigned_routes). '
            .'Also returns linked HR employee and driver default route when present. '
            .'Use for "which routes does X operate?", "what routes is Chege on?", or any user/route assignment question. '
            .'Pass user_name (full name or username) and/or user_id. Never invent route assignments.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_name' => [
                    'type' => 'string',
                    'description' => 'Full name or login username (e.g. Chege, CHEGE).',
                ],
                'username' => [
                    'type' => 'string',
                    'description' => 'Exact or partial login username.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id when known from an @User mention.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Fallback search when user_name is unknown.',
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
            || $this->permissions->hasPermission($user, 'admin.users.view', $gate)
            || $this->permissions->hasPermission($user, 'admin.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate)
            || $this->permissions->hasPermission($user, 'fulfillment.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view user or route assignment data.',
                'screens' => $this->screens(),
            ];
        }

        $orgId = (int) $organization->id;
        $userId = (int) ($arguments['user_id'] ?? 0);
        $needle = trim((string) (
            $arguments['user_name']
            ?? $arguments['username']
            ?? $arguments['query']
            ?? ''
        ));

        $target = null;
        if ($userId > 0) {
            $target = User::query()
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->with([
                    'role:id,role_name',
                    'branch:id,branch_name',
                    'assignedRoutes:id,organization_id,branch_id,route_name,direction,is_active',
                ])
                ->find($userId);
            if (! $target) {
                return [
                    'error' => true,
                    'message' => 'No user found for that id in this organization.',
                    'screens' => $this->screens(),
                ];
            }
        } elseif ($needle !== '') {
            $resolved = $this->resolveUserByName($orgId, $needle, true);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, ['screens' => $this->screens()]);
            }
            $target = $resolved['user'];
        } else {
            return [
                'error' => true,
                'message' => 'Provide user_name, username, query, or user_id.',
                'screens' => $this->screens(),
            ];
        }

        return [
            'user' => $this->presentUser($target, $orgId),
            'screens' => [
                ['label' => 'Users', 'path' => '/admin/users'],
                ['label' => 'Routes', 'path' => '/fulfillment/routes'],
                ['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile'],
                ...$this->screens(),
            ],
            'tip' => 'Answer with the user\'s full name and username. List assigned_routes (route_name) clearly. '
                .'Never claim Centrix lacks user-to-route mapping when assigned_routes is present. '
                .'For route order activity, call get_route_details or get_route_orders next.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentUser(User $target, int $orgId): array
    {
        $routes = $this->assignedRoutesFor($target, $orgId);
        $driver = $this->driverFor($target, $orgId);
        $employee = $this->employeeFor($target, $orgId);
        $routeActivity = $this->recentRouteActivity((int) $target->id, $orgId, array_column($routes, 'id'));

        return [
            'username' => (string) $target->username,
            'full_name' => (string) ($target->full_name ?: $target->username),
            'email' => $target->email ? (string) $target->email : null,
            'is_active' => (bool) $target->is_active,
            'role' => $target->role?->role_name,
            'branch' => $target->branch?->branch_name,
            'login_channels' => is_array($target->login_channels) ? $target->login_channels : [],
            'mobile_order_scope' => $target->mobile_order_scope ? (string) $target->mobile_order_scope : null,
            'assigned_routes' => $routes,
            'assigned_route_count' => count($routes),
            'driver' => $driver,
            'employee' => $employee,
            'recent_route_sales_30d' => $routeActivity,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function assignedRoutesFor(User $target, int $orgId): array
    {
        $byId = [];

        foreach ($target->assignedRoutes ?? [] as $route) {
            if ((int) ($route->organization_id ?? 0) !== $orgId && $route->organization_id !== null) {
                continue;
            }
            $byId[(int) $route->id] = $this->presentRoute($route);
        }

        $legacyId = (int) ($target->assigned_route_id ?? 0);
        if ($legacyId > 0 && ! isset($byId[$legacyId])) {
            $legacy = RouteModel::query()
                ->where('id', $legacyId)
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                })
                ->first();
            if ($legacy) {
                $byId[$legacyId] = $this->presentRoute($legacy);
            }
        }

        return array_values($byId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRoute(RouteModel $route): array
    {
        return [
            'id' => (int) $route->id,
            'route_name' => (string) ($route->route_name ?? ''),
            'direction' => $route->direction ? (string) $route->direction : null,
            'is_active' => (bool) ($route->is_active ?? true),
            'branch_id' => $route->branch_id !== null ? (int) $route->branch_id : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function driverFor(User $target, int $orgId): ?array
    {
        if (! Schema::hasTable('drivers')) {
            return null;
        }

        $driver = Driver::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $target->id)
            ->with(['defaultRoute:id,route_name,direction,is_active'])
            ->first();

        if (! $driver) {
            return null;
        }

        return [
            'driver_code' => $driver->driver_code ? (string) $driver->driver_code : null,
            'full_name' => (string) ($driver->full_name ?: $target->full_name),
            'is_active' => (bool) $driver->is_active,
            'default_route' => $driver->defaultRoute
                ? $this->presentRoute($driver->defaultRoute)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function employeeFor(User $target, int $orgId): ?array
    {
        if (! Schema::hasTable('employees')) {
            return null;
        }

        $employee = Employee::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $target->id)
            ->first(['id', 'full_name', 'first_name', 'last_name', 'employee_code', 'job_title']);

        if (! $employee) {
            return null;
        }

        return [
            'name' => (string) ($employee->full_name ?: trim($employee->first_name.' '.$employee->last_name)),
            'employee_code' => $employee->employee_code ? (string) $employee->employee_code : null,
            'job_title' => $employee->job_title ? (string) $employee->job_title : null,
            'path' => '/hr/employees/'.$employee->id,
        ];
    }

    /**
     * @param  list<int>  $routeIds
     * @return list<array<string, mixed>>
     */
    protected function recentRouteActivity(int $userId, int $orgId, array $routeIds): array
    {
        if ($routeIds === [] || ! Schema::hasTable('sales')) {
            return [];
        }

        $from = now()->subDays(30)->toDateString();
        $rows = Sale::query()
            ->where('organization_id', $orgId)
            ->where('cashier_id', $userId)
            ->whereIn('route_id', $routeIds)
            ->whereDate(DB::raw('COALESCE(delivery_date, created_at)'), '>=', $from)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('route_id, COUNT(*) as order_count, COALESCE(SUM(order_total), 0) as order_total')
            ->groupBy('route_id')
            ->get();

        $names = RouteModel::query()
            ->whereIn('id', $routeIds)
            ->pluck('route_name', 'id');

        return $rows->map(fn ($row) => [
            'route_id' => (int) $row->route_id,
            'route_name' => (string) ($names[(int) $row->route_id] ?? ''),
            'order_count' => (int) $row->order_count,
            'order_total' => round((float) $row->order_total, 2),
        ])->values()->all();
    }

    /** @return list<array{label: string, path: string}> */
    protected function screens(): array
    {
        return [
            ['label' => 'Routes', 'path' => '/fulfillment/routes'],
            ['label' => 'Drivers', 'path' => '/fulfillment/drivers'],
        ];
    }
}
