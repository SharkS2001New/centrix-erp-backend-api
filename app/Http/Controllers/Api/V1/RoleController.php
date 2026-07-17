<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cache\CapabilitiesCacheInvalidator;
use App\Services\Erp\ErpContext;
use App\Services\Erp\IndustryRegistry;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Role::class;
    }

    protected function scopesByOrganization(): bool
    {
        return false;
    }

    public function index(Request $request)
    {
        $orgId = $this->access()->organizationId($request->user(), $request);
        $query = Role::query()->where(function ($q) use ($orgId) {
            $q->whereNull('organization_id');
            if ($orgId) {
                $q->orWhere('organization_id', $orgId);
            }
        });

        $perPage = min((int) $request->input('per_page', 25), 200);
        $payload = $query->paginate($perPage)->toArray();
        $ids = collect($payload['data'] ?? [])->pluck('id');
        $counts = User::query()
            ->whereIn('role_id', $ids)
            ->selectRaw('role_id, COUNT(*) as users_count')
            ->groupBy('role_id')
            ->pluck('users_count', 'role_id');

        $payload['data'] = collect($payload['data'] ?? [])->map(function ($row) use ($counts) {
            $row['users_count'] = (int) ($counts[$row['id']] ?? 0);

            return $row;
        })->all();

        return response()->json($payload);
    }

    public function permissions(Request $request, string $id, ?string $nestedId = null)
    {
        $role = $this->findRoleOrFail($request, $this->resolveResourceId($id, $nestedId));
        $gate = app(ErpContext::class)->gateForRequest($request);
        $includeAdmin = $this->includeAdminPermissionsWhenActing($request);

        return response()->json($this->rolePermissionsPayload($role, $gate, $includeAdmin));
    }

    public function syncPermissions(Request $request, string $id, ?string $nestedId = null)
    {
        $resourceId = $this->resolveResourceId($id, $nestedId);
        $role = $this->findRoleOrFail($request, $resourceId);
        $data = $request->validate([
            'permission_ids' => 'present|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        $permissionIds = collect($data['permission_ids'] ?? [])
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        $gate = app(ErpContext::class)->gateForRequest($request);
        $includeAdmin = $this->includeAdminPermissionsWhenActing($request);
        $allowedIds = PermissionMatrixService::enabledPermissionIds($gate, $includeAdmin);
        $permissionIds = array_values(array_intersect($permissionIds, $allowedIds));

        DB::transaction(function () use ($role, $permissionIds) {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        });

        CapabilitiesCacheInvalidator::forRole($role->fresh());

        return response()->json($this->rolePermissionsPayload($role, $gate, $includeAdmin));
    }

    public function permissionMatrix(Request $request)
    {
        PermissionMatrixService::ensure();

        $gate = app(ErpContext::class)->gateForRequest($request);
        $includeAdmin = $this->includeAdminPermissionsWhenActing($request);
        $permissions = Permission::query()->orderBy('module')->orderBy('permission_name')->get();
        $allowedIds = collect(PermissionMatrixService::enabledPermissionIds($gate, $includeAdmin))->flip();

        $profile = (string) ($gate->organization()?->deployment_profile ?? 'wholesale_retail');
        $industryAppIds = IndustryRegistry::permissionApplicationIdsForProfile($profile);
        // Industry list is the default set; also include any application that already has
        // enabled modules (e.g. hospitality org that later enables inventory/backoffice).
        $applications = collect(PermissionMatrixService::applicationsGroupedForUi($gate, $includeAdmin))
            ->filter(function (array $app) use ($industryAppIds) {
                if ($industryAppIds === []) {
                    return true;
                }
                if (in_array((string) ($app['id'] ?? ''), $industryAppIds, true)) {
                    return true;
                }

                return ($app['modules'] ?? []) !== [];
            })
            ->values()
            ->all();

        return response()->json([
            'permissions' => $permissions
                ->filter(fn (Permission $permission) => $allowedIds->has((int) $permission->id))
                ->values(),
            'applications' => $applications,
            'groups' => PermissionMatrixService::groupedForUi($gate, $includeAdmin),
            'modules' => PermissionMatrixService::modules(),
            'actions' => PermissionMatrixService::actions(),
            'industry' => IndustryRegistry::industryForProfile($profile),
        ]);
    }

    /** Platform acting-as runs when Administration is off — still show admin.* in the matrix. */
    private function includeAdminPermissionsWhenActing(Request $request): bool
    {
        return (bool) $request->attributes->get('acting_organization_id');
    }

    public function destroy(Request $request, string $id, ?string $nestedId = null)
    {
        $role = $this->findRoleOrFail($request, $this->resolveResourceId($id, $nestedId));
        $usersCount = User::query()->where('role_id', $role->id)->count();

        if ($usersCount > 0) {
            throw ValidationException::withMessages([
                'role' => "Cannot delete this role — {$usersCount} user(s) are still assigned. Reassign them first.",
            ]);
        }

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        $role->delete();

        return response()->json(null, 204);
    }

    private function findRoleOrFail(Request $request, string $id): Role
    {
        if ($id === '' || ! ctype_digit($id)) {
            abort(404, 'Role not found.');
        }

        $orgId = $this->access()->organizationId($request->user(), $request);

        return Role::query()
            ->where('id', (int) $id)
            ->where(function ($q) use ($orgId) {
                $q->whereNull('organization_id');
                if ($orgId) {
                    $q->orWhere('organization_id', $orgId);
                }
            })
            ->firstOrFail();
    }

    /** @return array{role_id: int, permission_ids: list<int>} */
    private function rolePermissionsPayload(
        Role $role,
        ?\App\Services\Erp\CapabilityGate $gate = null,
        bool $includeAdminWhenDisabled = false,
    ): array {
        $permissionIds = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission_id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        if ($gate !== null) {
            $allowed = array_flip(PermissionMatrixService::enabledPermissionIds($gate, $includeAdminWhenDisabled));
            $permissionIds = array_values(array_filter(
                $permissionIds,
                fn (int $id) => isset($allowed[$id]),
            ));
        }

        return [
            'role_id' => (int) $role->id,
            'permission_ids' => $permissionIds,
        ];
    }
}
