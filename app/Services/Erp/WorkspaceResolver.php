<?php

namespace App\Services\Erp;

use App\Models\User;
use App\Services\Auth\UserPermissionService;

class WorkspaceResolver
{
    /**
     * @return list<array{id: string, label: string, description: string, icon: string, home_path: string}>
     */
    public function availableForUser(?User $user, CapabilityGate $gate): array
    {
        if ($user?->is_super_admin) {
            return [];
        }

        $permissionMap = $user
            ? app(UserPermissionService::class)->permissionMapForUser($user, $gate)
            : [];

        $definitions = config('erp_workspaces', []);
        $available = [];

        foreach ($definitions as $id => $def) {
            if (! $this->workspaceAvailableToUser($user, $def, $gate, $permissionMap)) {
                continue;
            }

            $available[] = [
                'id' => (string) $id,
                'label' => (string) ($def['label'] ?? $id),
                'description' => (string) ($def['description'] ?? ''),
                'icon' => (string) ($def['icon'] ?? 'app'),
                'home_path' => $this->resolveWorkspaceHomePath((string) $id, $def, $permissionMap),
            ];
        }

        return $available;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, bool>  $permissionMap
     */
    protected function workspaceAvailableToUser(
        ?User $user,
        array $definition,
        CapabilityGate $gate,
        array $permissionMap,
    ): bool {
        if (! $this->workspaceModulesEnabled($definition, $gate)) {
            return false;
        }

        if ($user?->is_admin) {
            return true;
        }

        return $this->userHasWorkspacePermission($definition, $permissionMap);
    }

    /** @param  array<string, mixed>  $definition */
    protected function workspaceModulesEnabled(array $definition, CapabilityGate $gate): bool
    {
        foreach ($definition['module_keys'] ?? [] as $key) {
            if ($gate->enabled((string) $key)) {
                return true;
            }
        }

        foreach ($definition['domain_modules'] ?? [] as $module) {
            if ($gate->enabled((string) $module)) {
                return true;
            }
        }

        return ($definition['module_keys'] ?? []) === [] && ($definition['domain_modules'] ?? []) === [];
    }

    /** @param  array<string, mixed>  $definition @param  array<string, bool>  $permissionMap */
    protected function userHasWorkspacePermission(array $definition, array $permissionMap): bool
    {
        $entryPermission = $definition['entry_permission'] ?? null;
        if (is_string($entryPermission) && $entryPermission !== '') {
            return (bool) ($permissionMap[$entryPermission] ?? false);
        }

        $prefixes = $definition['permission_prefixes'] ?? [];
        if ($prefixes === []) {
            return true;
        }

        foreach ($permissionMap as $code => $granted) {
            if (! $granted) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $code, (string) $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, bool>  $permissionMap
     */
    protected function resolveWorkspaceHomePath(string $workspaceId, array $definition, array $permissionMap): string
    {
        foreach ($definition['home_path_by_permissions'] ?? [] as $rule) {
            $prefixes = $rule['prefixes'] ?? [];
            $path = $rule['path'] ?? null;
            if (! is_array($prefixes) || ! is_string($path) || $path === '') {
                continue;
            }

            foreach ($permissionMap as $code => $granted) {
                if (! $granted) {
                    continue;
                }
                foreach ($prefixes as $prefix) {
                    if (str_starts_with((string) $code, (string) $prefix)) {
                        return $path;
                    }
                }
            }
        }

        return (string) ($definition['home_path'] ?? '/dashboard');
    }
}
