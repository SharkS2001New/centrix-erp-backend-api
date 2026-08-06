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
     * Till operations live under Backoffice (not External POS).
     * View/EOD unlock the shell; create-only (cashiers) must not.
     */
    protected const BACKOFFICE_TILL_OPS_PERMISSION_CODES = [
        'pos.till_management.view',
        'pos.till_management.edit',
        'pos.end_of_day.view',
    ];

    /**
     * Shared report codes that belong to every workspace hub — must not unlock Backoffice alone
     * (e.g. Accountants with reports.hub.view + finance reports).
     */
    protected const BACKOFFICE_NON_ENTRY_REPORT_FEATURES = [
        'hub',
        'builder',
    ];

    /**
     * Admin finance/tax utilities that must not unlock the Administration workspace alone.
     * Granting these without core admin rights (users, roles, company, …) keeps the user
     * in Backoffice — use pricing_tax.* counterparts there instead.
     */
    protected const ADMIN_NON_ENTRY_FEATURES = [
        'kra_responses',
        'vat_rates',
        'till_printing',
        'discount_approvals',
        'notifications',
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
            if (! $this->workspaceAllowedByIndustry((string) $id, $gate)) {
                continue;
            }
            if (! $this->workspaceAvailableToUser((string) $id, $user, $def, $gate, $permissionMap)) {
                continue;
            }

            $available[] = $this->presentWorkspace((string) $id, $def, $gate, $permissionMap);
        }

        return $available;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, bool>  $permissionMap
     * @return array{id: string, label: string, description: string, icon: string, home_path: string}
     */
    protected function presentWorkspace(string $id, array $def, CapabilityGate $gate, array $permissionMap): array
    {
        $org = $gate->organization();
        $profile = $org?->deployment_profile ?? 'wholesale_retail';
        $industry = IndustryRegistry::industryForProfile((string) $profile);

        $label = (string) ($def['label'] ?? $id);
        $description = (string) ($def['description'] ?? '');

        // Hospitality tenants share Accounting / HR / Admin shells — keep labels distinct from retail Backoffice.
        if ($industry === 'hospitality' && $id === 'admin') {
            $label = 'Administration';
            $description = 'Hotel users, roles, branches, and company setup for this hospitality organization.';
        }

        return [
            'id' => $id,
            'label' => $label,
            'description' => $description,
            'icon' => (string) ($def['icon'] ?? 'app'),
            'home_path' => $this->resolveWorkspaceHomePath($id, $def, $permissionMap),
        ];
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
        // Retail External POS uses the POS token channel.
        // Hotel POS is a web terminal that calls hospitality APIs (backoffice channel),
        // same as Hotel Backoffice / Accounting / HR / Admin.
        return match ($workspaceId) {
            'pos' => UserLoginChannelService::POS,
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

        // Org admins still go through permission checks. Their permission map already
        // includes every feature for org-enabled modules (UserPermissionService).
        return $this->userHasWorkspacePermission($workspaceId, $definition, $permissionMap);
    }

    /** @param  array<string, mixed>  $definition */
    protected function workspaceModulesEnabled(array $definition, CapabilityGate $gate): bool
    {
        $moduleKeys = $definition['module_keys'] ?? [];
        // When explicit module_keys exist, they alone decide availability.
        // Do NOT fall through to domain_modules (inventory/customers_suppliers are shared
        // by retail Backoffice and Hotel Backoffice and must not unlock the other industry).
        if ($moduleKeys !== []) {
            foreach ($moduleKeys as $key) {
                if ($gate->enabled((string) $key)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($definition['domain_modules'] ?? [] as $module) {
            if ($gate->enabled((string) $module)) {
                return true;
            }
        }

        return ($definition['domain_modules'] ?? []) === [];
    }

    /**
     * Commerce vs hospitality industries never share POS / backoffice shells.
     */
    protected function workspaceAllowedByIndustry(string $workspaceId, CapabilityGate $gate): bool
    {
        $org = $gate->organization();
        $profile = $org?->deployment_profile ?? 'wholesale_retail';
        $industry = IndustryRegistry::industryForProfile((string) $profile);

        if ($industry === 'hospitality') {
            return ! in_array($workspaceId, ['pos', 'backoffice', 'distribution'], true);
        }

        // Retail & Distribution (and other commerce profiles).
        return ! in_array($workspaceId, ['hotel_bar_pos', 'hospitality_backoffice'], true);
    }

    /** @param  array<string, mixed>  $definition @param  array<string, bool>  $permissionMap */
    protected function userHasWorkspacePermission(string $workspaceId, array $definition, array $permissionMap): bool
    {
        $entryPermission = $definition['entry_permission'] ?? null;
        if (is_string($entryPermission) && $entryPermission !== '') {
            return (bool) ($permissionMap[$entryPermission] ?? false);
        }

        if ($workspaceId === 'backoffice') {
            return $this->userHasBackofficePermission($permissionMap);
        }

        if ($workspaceId === 'admin') {
            return $this->userHasAdminPermission($permissionMap);
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
     * Administration entry: core org-admin rights only.
     * VAT rates / KRA device log / till printing / notifications alone must not unlock it.
     *
     * @param  array<string, bool>  $permissionMap
     */
    protected function userHasAdminPermission(array $permissionMap): bool
    {
        foreach ($permissionMap as $code => $granted) {
            if (! $granted) {
                continue;
            }
            if ($this->permissionUnlocksAdmin((string) $code)) {
                return true;
            }
        }

        return false;
    }

    protected function permissionUnlocksAdmin(string $code): bool
    {
        if (! str_starts_with($code, 'admin.')) {
            return false;
        }

        $parts = explode('.', $code);
        $feature = $parts[1] ?? '';
        if ($feature === '' || in_array($feature, self::ADMIN_NON_ENTRY_FEATURES, true)) {
            return false;
        }

        return true;
    }

    /**
     * Backoffice entry: operational rights, till-ops view/EOD, or operational report features.
     * Finance/HR/logistics reports, report hub, and Business summary alone must not unlock it.
     *
     * @param  array<string, bool>  $permissionMap
     */
    protected function userHasBackofficePermission(array $permissionMap): bool
    {
        foreach ($permissionMap as $code => $granted) {
            if (! $granted) {
                continue;
            }
            if ($this->permissionUnlocksBackoffice((string) $code)) {
                return true;
            }
        }

        return false;
    }

    protected function permissionUnlocksBackoffice(string $code): bool
    {
        if (in_array($code, self::BACKOFFICE_POS_SHARED_PERMISSION_CODES, true)) {
            return false;
        }

        if (in_array($code, self::BACKOFFICE_TILL_OPS_PERMISSION_CODES, true)) {
            return true;
        }

        // Overview is a landing page inside Backoffice, not an entry right for Accountants/HR.
        if ($code === 'dashboard.overview.view') {
            return false;
        }

        if (str_starts_with($code, 'dashboard.')) {
            return true;
        }

        if (str_starts_with($code, 'reports.')) {
            return $this->isBackofficeOperationalReportPermission($code);
        }

        foreach (['catalogue.', 'customers.', 'sales.', 'inventory.', 'purchasing.', 'pricing_tax.'] as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function isBackofficeOperationalReportPermission(string $code): bool
    {
        $parts = explode('.', $code);
        if (count($parts) < 3 || $parts[0] !== 'reports') {
            return false;
        }

        $feature = $parts[1];
        if (in_array($feature, self::BACKOFFICE_NON_ENTRY_REPORT_FEATURES, true)) {
            return false;
        }

        $allowed = config('permission_applications.applications.backoffice.module_features.reports', []);
        if (! is_array($allowed)) {
            return false;
        }

        return in_array($feature, array_map('strval', $allowed), true);
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
