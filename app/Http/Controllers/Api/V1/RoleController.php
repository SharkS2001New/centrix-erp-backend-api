<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\RoleTemplateService;
use App\Services\Auth\UserPermissionService;
use App\Services\Cache\CapabilitiesCacheInvalidator;
use App\Services\Cache\OrganizationCache;
use App\Services\Erp\CapabilityGate;
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
        $gate = app(ErpContext::class)->gateForRequest($request);
        $profile = (string) ($gate->organization()?->deployment_profile ?? 'wholesale_retail');
        $industry = IndustryRegistry::industryForProfile($profile);
        $allowedTemplateNames = app(RoleTemplateService::class)->templateRoleNamesForIndustry($industry);
        $allTemplateNames = array_keys(config('role_templates.roles', []));
        $hiddenTemplateNames = array_values(array_diff($allTemplateNames, $allowedTemplateNames));

        $query = Role::query()->where(function ($q) use ($orgId) {
            $q->whereNull('organization_id');
            if ($orgId) {
                $q->orWhere('organization_id', $orgId);
            }
        });

        // Hide opposite-industry global templates (keep org-custom + Administrator/Admin/legacy).
        if ($hiddenTemplateNames !== []) {
            $query->where(function ($q) use ($hiddenTemplateNames) {
                $q->whereNotIn('role_name', $hiddenTemplateNames)
                    ->orWhereNotNull('organization_id');
            });
        }

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
        $industry = $this->industryForRequest($gate);

        return response()->json($this->rolePermissionsPayload($role, $gate, $includeAdmin, $industry));
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
        $industry = $this->industryForRequest($gate);
        $allowedIds = PermissionMatrixService::industryEnabledPermissionIds($industry, $gate, $includeAdmin);
        $allowedFlip = array_flip($allowedIds);
        $permissionIds = array_values(array_filter(
            $permissionIds,
            fn (int $id) => isset($allowedFlip[$id]),
        ));

        // Preserve grants outside the currently editable matrix (disabled modules, and for
        // global roles also other industries) so saving the visible set does not strip them.
        $preservedIds = [];
        $existingIds = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        foreach ($existingIds as $existingId) {
            if (! isset($allowedFlip[$existingId])) {
                $preservedIds[] = $existingId;
            }
        }

        $finalIds = array_values(array_unique(array_merge($permissionIds, $preservedIds)));

        DB::transaction(function () use ($role, $finalIds) {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            foreach ($finalIds as $permissionId) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        });

        app(UserPermissionService::class)->forgetRolePermissionCache((int) $role->id);
        CapabilitiesCacheInvalidator::forRole($role->fresh());

        return response()->json($this->rolePermissionsPayload($role, $gate, $includeAdmin, $industry));
    }

    public function permissionMatrix(Request $request)
    {
        $gate = app(ErpContext::class)->gateForRequest($request);
        $includeAdmin = $this->includeAdminPermissionsWhenActing($request);
        $profile = (string) ($gate->organization()?->deployment_profile ?? 'wholesale_retail');
        $industry = IndustryRegistry::industryForProfile($profile);
        $orgId = (int) ($gate->organization()?->id ?? 0);

        $build = static fn (): array => PermissionMatrixService::uiMatrixPayload(
            $gate,
            $includeAdmin,
            $industry,
        );

        // Cache per org + capabilities generation so module/section edits invalidate
        // via CapabilitiesCacheInvalidator → OrganizationCache::invalidateCapabilities.
        if ($orgId > 0) {
            $cacheKey = sprintf(
                'roles_perm_matrix:v2:%s:%s:%d:%d',
                PermissionMatrixService::registryCacheVersion(),
                $industry,
                $includeAdmin ? 1 : 0,
                OrganizationCache::capabilitiesVersion($orgId),
            );

            return response()->json(
                OrganizationCache::remember($orgId, $cacheKey, 600, $build),
            );
        }

        return response()->json($build());
    }

    /** Platform acting-as runs when Administration is off — still show admin.* in the matrix. */
    private function includeAdminPermissionsWhenActing(Request $request): bool
    {
        return (bool) $request->attributes->get('acting_organization_id');
    }

    private function industryForRequest(CapabilityGate $gate): string
    {
        $profile = (string) ($gate->organization()?->deployment_profile ?? 'wholesale_retail');

        return IndustryRegistry::industryForProfile($profile);
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
        app(UserPermissionService::class)->forgetRolePermissionCache((int) $role->id);
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
        ?CapabilityGate $gate = null,
        bool $includeAdminWhenDisabled = false,
        ?string $industry = null,
    ): array {
        // Return raw grants. Filtering through industryEnabledPermissionIds rebuilt the
        // full applications tree on every role click (~Roles page lag). The matrix UI only
        // renders visible cells; syncPermissions still strips / preserves out-of-matrix ids.
        unset($gate, $includeAdminWhenDisabled, $industry);

        $permissionIds = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission_id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        return [
            'role_id' => (int) $role->id,
            'permission_ids' => $permissionIds,
        ];
    }
}
