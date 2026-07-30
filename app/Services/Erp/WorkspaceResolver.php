<?php

namespace App\Services\Erp;

use App\Models\User;
use App\Services\Auth\UserLoginChannelService;
use App\Services\Auth\UserPermissionService;

class WorkspaceResolver
{
    /** POS checkout needs these shared lookups, but they should not unlock Backoffice. */
    protected const BACKOFFICE_POS_SHARED_PERMISSION_CODES = [
        'sales.create',
        'catalogue.view',
        'catalogue.products.view',
        'customers.view',
        'customers.customers.view',
    ];

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
            if (! $this->workspaceAllowedByLoginChannels((string) $id, $user)) {
                continue;
            }
            if (! $this->workspaceAvailableToUser((string) $id, $user, $def, $gate, $permissionMap)) {
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

    protected function workspaceAllowedByLoginChannels(string $workspaceId, ?User $user): bool
    {
        if (! $user) {
            return true;
        }

        $channels = app(UserLoginChannelService::class)->channelsFor($user);
        $workspaceChannel = $this->workspaceLoginChannel($workspaceId);

        return in_array($workspaceChannel, $channels, true);
    }

    protected function workspaceLoginChannel(string $workspaceId): string
    {
        return match ($workspaceId) {
            'pos', 'hotel_bar_pos' => UserLoginChannelService::POS,
            default => UserLoginChannelService::BACKOFFICE,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, bool>  $permissionMap
     */
    protected function workspaceAvailableToUser(
        string $workspaceId,
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

        return $this->userHasWorkspacePermission($workspaceId, $definition, $permissionMap);
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
    protected function userHasWorkspacePermission(string $workspaceId, array $definition, array $permissionMap): bool
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
            if (
                $workspaceId === 'backoffice'
                && in_array((string) $code, self::BACKOFFICE_POS_SHARED_PERMISSION_CODES, true)
            ) {
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
