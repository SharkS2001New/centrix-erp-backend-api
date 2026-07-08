<?php

namespace App\Services\Organization;

use App\Models\Branch;
use App\Models\RouteModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reassign routes to the correct organization when legacy data treated routes as global.
 *
 * Business rule: single-letter routes A–E belong to one org; all other route names belong to another.
 */
class RouteOrganizationRepairService
{
    /** @return array<string, int|list<string>> */
    public function preview(int $letterOrgId, int $defaultOrgId): array
    {
        $routes = RouteModel::query()->orderBy('id')->get();
        $letterRoutes = [];
        $otherRoutes = [];
        $alreadyCorrect = 0;
        $conflicts = [];

        foreach ($routes as $route) {
            $targetOrgId = $this->targetOrganizationId((string) $route->route_name, $letterOrgId, $defaultOrgId);
            if ((int) $route->organization_id === $targetOrgId) {
                $alreadyCorrect++;

                continue;
            }

            $bucket = $this->isLetterRouteName((string) $route->route_name) ? 'letter' : 'other';
            if ($bucket === 'letter') {
                $letterRoutes[] = $this->describeRoute($route, $targetOrgId);
            } else {
                $otherRoutes[] = $this->describeRoute($route, $targetOrgId);
            }

            $canonical = $this->findCanonicalRoute($targetOrgId, (string) $route->route_name, (int) $route->id);
            if ($canonical) {
                $conflicts[] = "Route #{$route->id} «{$route->route_name}» merges into #{$canonical->id} on org {$targetOrgId}";
            }
        }

        return [
            'letter_org_id' => $letterOrgId,
            'default_org_id' => $defaultOrgId,
            'total_routes' => $routes->count(),
            'already_correct' => $alreadyCorrect,
            'letter_routes_to_move' => count($letterRoutes),
            'other_routes_to_move' => count($otherRoutes),
            'letter_routes' => $letterRoutes,
            'other_routes' => $otherRoutes,
            'name_conflicts' => $conflicts,
        ];
    }

    /** @return array<string, int> */
    public function run(int $letterOrgId, int $defaultOrgId, bool $assignHeadOfficeBranch = true): array
    {
        $stats = [
            'routes_reassigned' => 0,
            'routes_merged' => 0,
            'customers_repointed' => 0,
            'customers_org_aligned' => 0,
            'customers_branch_aligned' => 0,
            'sales_route_repointed' => 0,
            'users_route_cleared' => 0,
            'drivers_route_repointed' => 0,
            'temporary_carts_repointed' => 0,
            'dispatch_trips_repointed' => 0,
            'route_schedules_repointed' => 0,
            'loading_lists_repointed' => 0,
            'picking_lists_repointed' => 0,
        ];

        DB::transaction(function () use ($letterOrgId, $defaultOrgId, $assignHeadOfficeBranch, &$stats) {
            $routes = RouteModel::query()->orderBy('id')->get();

            foreach ($routes as $route) {
                $targetOrgId = $this->targetOrganizationId((string) $route->route_name, $letterOrgId, $defaultOrgId);
                if ((int) $route->organization_id === $targetOrgId) {
                    continue;
                }

                $canonical = $this->findCanonicalRoute($targetOrgId, (string) $route->route_name, (int) $route->id);
                if ($canonical) {
                    $stats = $this->mergeRouteInto($stats, (int) $route->id, (int) $canonical->id);
                    $stats['routes_merged']++;

                    continue;
                }

                RouteModel::query()
                    ->where('id', $route->id)
                    ->update(['organization_id' => $targetOrgId]);
                $stats['routes_reassigned']++;
            }

            $stats['customers_org_aligned'] = $this->alignCustomersToRouteOrganization();
            if ($assignHeadOfficeBranch) {
                $stats['customers_branch_aligned'] = $this->alignRouteCustomersToHeadOffice();
            }

            $stats['users_route_cleared'] = $this->clearMismatchedUserAssignedRoutes();
        });

        return $stats;
    }

    public function isLetterRouteName(string $routeName): bool
    {
        return (bool) preg_match('/^[A-Ea-e]$/', trim($routeName));
    }

    public function targetOrganizationId(string $routeName, int $letterOrgId, int $defaultOrgId): int
    {
        return $this->isLetterRouteName($routeName) ? $letterOrgId : $defaultOrgId;
    }

    protected function findCanonicalRoute(int $organizationId, string $routeName, int $excludeRouteId): ?RouteModel
    {
        return RouteModel::query()
            ->where('organization_id', $organizationId)
            ->where('route_name', $routeName)
            ->where('id', '<>', $excludeRouteId)
            ->first();
    }

    /** @return array{id: int, name: string, from_org: ?int, to_org: int} */
    protected function describeRoute(RouteModel $route, int $targetOrgId): array
    {
        return [
            'id' => (int) $route->id,
            'name' => (string) $route->route_name,
            'from_org' => $route->organization_id !== null ? (int) $route->organization_id : null,
            'to_org' => $targetOrgId,
        ];
    }

    /** @param  array<string, int>  $stats */
    protected function mergeRouteInto(array $stats, int $fromRouteId, int $toRouteId): array
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'route_id')) {
            $stats['customers_repointed'] += DB::table('customers')
                ->where('route_id', $fromRouteId)
                ->update(['route_id' => $toRouteId]);
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'route_id')) {
            $stats['sales_route_repointed'] += DB::table('sales')
                ->where('route_id', $fromRouteId)
                ->update(['route_id' => $toRouteId]);
        }

        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'route_id')) {
            $stats['temporary_carts_repointed'] += DB::table('temporary_carts')
                ->where('route_id', $fromRouteId)
                ->update(['route_id' => $toRouteId]);
        }

        if (Schema::hasTable('dispatch_trips') && Schema::hasColumn('dispatch_trips', 'route_id')) {
            $stats['dispatch_trips_repointed'] += DB::table('dispatch_trips')
                ->where('route_id', $fromRouteId)
                ->update(['route_id' => $toRouteId]);
        }

        if (Schema::hasTable('dispatch_trip_routes') && Schema::hasColumn('dispatch_trip_routes', 'route_id')) {
            DB::table('dispatch_trip_routes')
                ->where('route_id', $fromRouteId)
                ->delete();
        }

        if (Schema::hasTable('route_schedules') && Schema::hasColumn('route_schedules', 'route_id')) {
            $stats['route_schedules_repointed'] += DB::table('route_schedules')
                ->where('route_id', $fromRouteId)
                ->update(['route_id' => $toRouteId]);
        }

        if (Schema::hasTable('loading_lists') && Schema::hasColumn('loading_lists', 'route_id')) {
            $stats['loading_lists_repointed'] += DB::table('loading_lists')
                ->where('route_id', $fromRouteId)
                ->update(['route_id' => $toRouteId]);
        }

        if (Schema::hasTable('picking_lists') && Schema::hasColumn('picking_lists', 'route_id')) {
            $stats['picking_lists_repointed'] += DB::table('picking_lists')
                ->where('route_id', $fromRouteId)
                ->update(['route_id' => $toRouteId]);
        }

        if (Schema::hasTable('drivers') && Schema::hasColumn('drivers', 'default_route_id')) {
            $stats['drivers_route_repointed'] += DB::table('drivers')
                ->where('default_route_id', $fromRouteId)
                ->update(['default_route_id' => $toRouteId]);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'assigned_route_id')) {
            DB::table('users')
                ->where('assigned_route_id', $fromRouteId)
                ->update(['assigned_route_id' => $toRouteId]);
        }

        RouteModel::query()->where('id', $fromRouteId)->delete();

        return $stats;
    }

    protected function alignCustomersToRouteOrganization(): int
    {
        if (! Schema::hasTable('customers')
            || ! Schema::hasColumn('customers', 'route_id')
            || ! Schema::hasColumn('customers', 'organization_id')) {
            return 0;
        }

        return DB::affectingStatement('
            UPDATE customers c
            INNER JOIN routes r ON r.id = c.route_id
            SET c.organization_id = r.organization_id
            WHERE c.route_id IS NOT NULL
              AND r.organization_id IS NOT NULL
              AND (c.organization_id IS NULL OR c.organization_id <> r.organization_id)
        ');
    }

    protected function alignRouteCustomersToHeadOffice(): int
    {
        if (! Schema::hasTable('customers')
            || ! Schema::hasColumn('customers', 'route_id')
            || ! Schema::hasColumn('customers', 'branch_id')) {
            return 0;
        }

        $updated = 0;
        $organizationIds = RouteModel::query()
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $headOfficeBranchId = $this->headOfficeBranchId((int) $organizationId);
            if (! $headOfficeBranchId) {
                continue;
            }

            $updated += DB::affectingStatement('
                UPDATE customers c
                INNER JOIN routes r ON r.id = c.route_id
                SET c.branch_id = ?
                WHERE c.route_id IS NOT NULL
                  AND r.organization_id = ?
                  AND (c.branch_id IS NULL OR c.branch_id <> ?)
            ', [$headOfficeBranchId, (int) $organizationId, $headOfficeBranchId]);
        }

        return $updated;
    }

    protected function clearMismatchedUserAssignedRoutes(): int
    {
        if (! Schema::hasTable('users')
            || ! Schema::hasColumn('users', 'assigned_route_id')
            || ! Schema::hasColumn('users', 'organization_id')) {
            return 0;
        }

        return DB::affectingStatement('
            UPDATE users u
            LEFT JOIN routes r ON r.id = u.assigned_route_id
            SET u.assigned_route_id = NULL
            WHERE u.assigned_route_id IS NOT NULL
              AND (
                r.id IS NULL
                OR r.organization_id IS NULL
                OR u.organization_id IS NULL
                OR r.organization_id <> u.organization_id
              )
        ');
    }

    protected function headOfficeBranchId(int $organizationId): ?int
    {
        $branch = Branch::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) {
                $query->where('branch_code', 'HQ')
                    ->orWhere('branch_name', 'like', '%Head Office%');
            })
            ->orderBy('id')
            ->first();

        return $branch ? (int) $branch->id : null;
    }
}
