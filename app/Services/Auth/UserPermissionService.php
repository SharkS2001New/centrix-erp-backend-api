<?php

namespace App\Services\Auth;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserPermissionService
{
    /** @return list<int> */
    public function rolePermissionIds(?int $roleId): array
    {
        if (! $roleId) {
            return [];
        }

        return DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function grantIds(int $userId): array
    {
        return $this->overrideIds($userId, 'grant');
    }

    /** @return list<int> */
    public function denyIds(int $userId): array
    {
        return $this->overrideIds($userId, 'deny');
    }

    /** @return list<int> */
    protected function overrideIds(int $userId, string $effect): array
    {
        return DB::table('user_permission_overrides')
            ->where('user_id', $userId)
            ->where('effect', $effect)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function effectivePermissionIds(User $user): array
    {
        if ($user->is_admin) {
            return Permission::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $roleIds = collect($this->rolePermissionIds($user->role_id));
        $grants = collect($this->grantIds((int) $user->id));
        $denies = collect($this->denyIds((int) $user->id));

        return $roleIds
            ->merge($grants)
            ->diff($denies)
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function hasPermission(User $user, string $permissionCode): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($this->hasDirectPermission($user, $permissionCode)) {
            return true;
        }

        $aliases = config('permission_aliases', []);

        foreach ($aliases[$permissionCode] ?? [] as $aliasCode) {
            if ($this->hasDirectPermission($user, $aliasCode)) {
                return true;
            }
        }

        return false;
    }

    protected function hasDirectPermission(User $user, string $permissionCode): bool
    {
        $permissionId = Permission::query()
            ->where('permission_code', $permissionCode)
            ->value('id');

        if (! $permissionId) {
            return false;
        }

        return in_array((int) $permissionId, $this->effectivePermissionIds($user), true);
    }

    /** @return array<string, bool> */
    public function permissionMapForUser(User $user): array
    {
        if ($user->is_admin) {
            return Permission::query()
                ->pluck('permission_code')
                ->mapWithKeys(fn ($code) => [$code => true])
                ->all();
        }

        $codes = Permission::query()
            ->whereIn('id', $this->effectivePermissionIds($user))
            ->pluck('permission_code');

        $map = [];
        foreach ($codes as $code) {
            $map[$code] = true;
        }

        foreach (config('permission_aliases', []) as $capability => $aliases) {
            if ($map[$capability] ?? false) {
                continue;
            }
            foreach ($aliases as $alias) {
                if ($map[$alias] ?? false) {
                    $map[$capability] = true;
                    break;
                }
            }
        }

        return $map;
    }

    /** @param  list<int>  $grantedIds
     * @param  list<int>  $deniedIds
     */
    public function syncOverrides(int $userId, array $grantedIds, array $deniedIds): void
    {
        $grantedIds = array_values(array_unique(array_map('intval', $grantedIds)));
        $deniedIds = array_values(array_unique(array_map('intval', $deniedIds)));

        $overlap = array_intersect($grantedIds, $deniedIds);
        if ($overlap !== []) {
            throw ValidationException::withMessages([
                'permission_ids' => ['A permission cannot be both granted and denied for the same user.'],
            ]);
        }

        DB::transaction(function () use ($userId, $grantedIds, $deniedIds) {
            DB::table('user_permission_overrides')->where('user_id', $userId)->delete();

            foreach ($grantedIds as $permissionId) {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'effect' => 'grant',
                ]);
            }

            foreach ($deniedIds as $permissionId) {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'effect' => 'deny',
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    public function describeForUser(User $user): array
    {
        return [
            'user_id' => (int) $user->id,
            'role_id' => (int) $user->role_id,
            'role_permission_ids' => $this->rolePermissionIds($user->role_id),
            'granted_permission_ids' => $this->grantIds((int) $user->id),
            'denied_permission_ids' => $this->denyIds((int) $user->id),
            'effective_permission_ids' => $this->effectivePermissionIds($user),
        ];
    }
}
