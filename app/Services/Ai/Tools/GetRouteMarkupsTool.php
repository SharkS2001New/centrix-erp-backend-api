<?php

namespace App\Services\Ai\Tools;

use App\Models\RouteModel;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * List sales/distribution routes with their route_markup_price (KES).
 */
class GetRouteMarkupsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_route_markups';
    }

    public function description(): string
    {
        return 'List Centrix sales/distribution routes with each route\'s markup amount '
            .'(routes.route_markup_price in KES). Use for questions like '
            .'"what is the markup amount for each route?", "route markups", or '
            .'"how much markup does route X add?". This is the flat route markup added on mobile/POS '
            .'when add_route_markup_prices is enabled — not retail package tier markups. '
            .'Always call this tool; never invent markup figures.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'active_only' => [
                    'type' => 'boolean',
                    'description' => 'When true (default), only include active routes.',
                ],
                'route_name' => [
                    'type' => 'string',
                    'description' => 'Optional filter: route name or partial name.',
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
            || $this->permissions->hasPermission($user, 'admin.view', $gate)
            || $this->permissions->hasPermission($user, 'admin.routes.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view route markup data.',
                'screens' => $this->screens(),
            ];
        }

        if (! Schema::hasTable('routes')) {
            return [
                'error' => true,
                'message' => 'Routes are not available in this database.',
                'screens' => $this->screens(),
            ];
        }

        $orgId = (int) $organization->id;
        $activeOnly = array_key_exists('active_only', $arguments)
            ? (bool) $arguments['active_only']
            : true;
        $needle = trim((string) ($arguments['route_name'] ?? ''));

        $query = RouteModel::query()
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', $orgId)->orWhereNull('organization_id');
            })
            ->orderBy('route_name');

        if ($activeOnly && Schema::hasColumn('routes', 'is_active')) {
            $query->where('is_active', true);
        }

        if ($needle !== '') {
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(route_name) LIKE ?', ['%'.mb_strtolower($needle).'%']);
                if (ctype_digit($needle)) {
                    $q->orWhere('id', (int) $needle);
                }
            });
        }

        $routes = $query->limit(200)->get();
        $rows = $routes->map(function (RouteModel $route) {
            $markup = (float) ($route->route_markup_price ?? 0);

            return [
                'route_id' => (int) $route->id,
                'route_name' => (string) ($route->route_name ?? ''),
                'direction' => $route->direction ? (string) $route->direction : null,
                'is_active' => (bool) ($route->is_active ?? true),
                'route_markup_price' => round($markup, 2),
                'route_markup_kes' => round($markup, 2),
            ];
        })->values()->all();

        $withMarkup = count(array_filter($rows, fn (array $r) => ($r['route_markup_price'] ?? 0) > 0));

        return [
            'organization_id' => $orgId,
            'currency' => 'KES',
            'route_count' => count($rows),
            'routes_with_markup' => $withMarkup,
            'routes' => $rows,
            'explanation' => 'route_markup_price is the flat KES markup stored on each route. '
                .'It is applied on qualifying mobile/POS lines when Sales → add route markup prices is enabled. '
                .'Retail packaging markups (/retail-package-settings) are separate.',
            'screens' => $this->screens(),
            'tip' => 'Answer with a markdown table of route_name and route_markup_price (KES). '
                .'Call out routes with zero markup. Link /routes or /fulfillment/routes to edit.',
        ];
    }

    /** @return list<array{label: string, path: string}> */
    protected function screens(): array
    {
        return [
            ['label' => 'Routes', 'path' => '/routes'],
            ['label' => 'Fulfillment routes', 'path' => '/fulfillment/routes'],
            ['label' => 'Sales settings', 'path' => '/admin/settings'],
        ];
    }
}
