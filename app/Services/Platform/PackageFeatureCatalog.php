<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Services\Erp\IndustryRegistry;

/**
 * Modules and permission features a payment package can sell.
 * External POS, the mobile app, and the manager app are modules in this list.
 */
class PackageFeatureCatalog
{
    /** @return array<string, list<array<string, mixed>>> */
    public function all(): array
    {
        $out = [];
        foreach (IndustryRegistry::ids() as $industry) {
            $out[$industry] = $this->forIndustry($industry);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function forIndustry(string $industry): array
    {
        $industry = $this->industry($industry);
        $allowed = array_flip(IndustryRegistry::permissionApplicationIdsForIndustry($industry));
        $modules = [];

        foreach (config('permission_applications.order', []) as $appId) {
            if (! isset($allowed[$appId])) {
                continue;
            }
            $definition = config("permission_applications.applications.{$appId}");
            if (! is_array($definition)) {
                continue;
            }
            $modules[] = $this->applicationModule((string) $appId, $definition);
        }

        if ($industry === 'spa') {
            $modules[] = $this->flagModule('spa', 'SPA & Kinyozi', 'Appointments, till, clients, and stock for this salon.', [
                'appointments' => 'Appointments',
                'pos' => 'Till',
                'clients' => 'Clients',
                'services' => 'Services',
                'staff' => 'Staff & commissions',
                'inventory' => 'Inventory',
                'expenses' => 'Expenses',
                'suppliers' => 'Suppliers',
                'reports' => 'Reports',
            ]);
        }

        if ($industry === 'driving') {
            $modules[] = $this->flagModule('driving', 'Driving School', 'Students, lessons, vehicles, and school fees.', [
                'vehicles' => 'Vehicles',
                'assessments' => 'Assessments',
                'fees' => 'Payments',
                'expenses' => 'Expenses',
            ]);
        }

        return $modules;
    }

    /**
     * Keep only modules this industry sells, and only features of modules that are on.
     *
     * @param  list<string>  $workspaceKeys
     * @param  array<string, mixed>  $featureKeys
     * @return array<string, list<string>>
     */
    public function normalize(string $industry, array $workspaceKeys, array $featureKeys): array
    {
        $modules = [];
        foreach ($this->forIndustry($industry) as $module) {
            $modules[(string) $module['id']] = $module;
        }

        $selected = [];
        foreach ($workspaceKeys as $key) {
            $id = strtolower(trim((string) $key));
            if ($id !== '' && isset($modules[$id])) {
                $selected[$id] = true;
            }
        }

        $out = [];
        foreach (array_keys($selected) as $moduleId) {
            $known = [];
            foreach ($modules[$moduleId]['features'] ?? [] as $feature) {
                $known[(string) $feature['id']] = true;
            }
            $picked = [];
            foreach ((array) ($featureKeys[$moduleId] ?? []) as $featureId) {
                $featureId = trim((string) $featureId);
                if ($featureId !== '' && isset($known[$featureId]) && ! in_array($featureId, $picked, true)) {
                    $picked[] = $featureId;
                }
            }
            if ($picked === [] && $known !== []) {
                $picked = array_keys($known);
            }
            $out[$moduleId] = $picked;
        }

        return $out;
    }

    /**
     * Saved package for this organisation. Null when the plan has no feature list,
     * so organisations on an older package keep the access they already have.
     *
     * @return array{modules: list<string>, features: array<string, list<string>>}|null
     */
    public function packageFor(?Organization $organization): ?array
    {
        if ($organization === null || $organization->id === null) {
            return null;
        }

        try {
            $subscription = PlatformSubscription::query()
                ->where('organization_id', (int) $organization->id)
                ->whereNotNull('plan_id')
                ->latest('id')
                ->first();
        } catch (\Throwable $e) {
            if (! \App\Support\SchemaProbe::hasTable('platform_subscriptions')) {
                return null;
            }
            throw $e;
        }
        $plan = $subscription?->plan;
        if (! $plan || ! is_array($plan->feature_keys)) {
            return null;
        }

        return [
            'modules' => array_values(array_map('strval', array_keys($plan->feature_keys))),
            'features' => $plan->feature_keys,
        ];
    }

    /**
     * Backoffice sidebar sections implied by the paid Backoffice features.
     * Null when this package does not list Backoffice features.
     *
     * @param  array<string, list<string>>  $featureKeys
     * @return list<string>|null
     */
    public function backofficeSections(array $featureKeys): ?array
    {
        if (! array_key_exists('backoffice', $featureKeys)) {
            return null;
        }

        $sections = [];
        foreach ($featureKeys['backoffice'] as $featureId) {
            foreach ($this->sectionsForFeature((string) $featureId) as $section) {
                $sections[$section] = true;
            }
        }

        return array_keys($sections);
    }

    /** @return list<string> */
    private function sectionsForFeature(string $featureId): array
    {
        [$module, $feature] = array_pad(explode('.', $featureId, 2), 2, '');

        return match ($module) {
            'dashboard' => ['dashboard'],
            'catalogue' => ['products'],
            'pricing_tax' => ['pricing_tax'],
            'shop_debtors' => ['shop_debtors'],
            'customers' => ['customers'],
            'investors' => ['investors'],
            'purchasing' => ['suppliers'],
            'reports' => ['reports'],
            'accounting' => $feature === 'expenses' ? ['expenses'] : [],
            'payments' => ['sales_orders'],
            'inventory' => in_array($feature, ['movements', 'transfers'], true) ? ['stock_movements'] : ['inventory'],
            'pos' => in_array($feature, ['till_management', 'end_of_day', 'payments_breakdown'], true) ? ['pos'] : [],
            'sales' => match ($feature) {
                'vouchers', 'loyalty_cards' => ['promotions'],
                'returns' => ['after_sales'],
                'loading_sheets', 'field_attendance' => ['field_sales'],
                default => ['sales_orders'],
            },
            default => [],
        };
    }

    /**
     * @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    public function filterPermissionMap(array $map, ?Organization $org): array
    {
        $allowed = $this->allowedFeatureSet($org);
        if ($allowed === null) {
            return $map;
        }

        return $this->filterMapToFeatures($map, $allowed);
    }

    /**
     * @param  array<string, bool>  $map
     * @param  array<string, true>  $allowed
     * @return array<string, bool>
     */
    public function filterMapToFeatures(array $map, array $allowed): array
    {
        $known = $this->knownPermissionFeatureIds();
        foreach (array_keys($map) as $code) {
            if (! is_string($code) || ! preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)\.[a-z0-9_]+$/', $code, $match)) {
                continue;
            }
            $featureId = $match[1].'.'.$match[2];
            if (isset($known[$featureId]) && ! isset($allowed[$featureId])) {
                $map[$code] = false;
            }
        }

        return $map;
    }

    /** @return array<string, true>|null */
    public function allowedFeatureSet(?Organization $org): ?array
    {
        $package = $this->packageFor($org);
        $licence = is_array($package) ? $package['features'] : ($org?->module_settings['licence']['features'] ?? null);
        if (! is_array($licence)) {
            return null;
        }

        $allowed = [];
        foreach ($licence as $featureIds) {
            if (! is_array($featureIds)) {
                continue;
            }
            foreach ($featureIds as $featureId) {
                $featureId = trim((string) $featureId);
                if ($featureId !== '') {
                    $allowed[$featureId] = true;
                }
            }
        }

        return $this->expandAllowedWithOrganizationApplications($allowed, $org);
    }

    /**
     * Platform-admin org application toggles can enable apps beyond the payment
     * package / setup-type baseline. Include those apps' permission features so
     * role grants and the matrix stay usable for the customized modules.
     *
     * @param  array<string, true>  $allowed
     * @return array<string, true>
     */
    public function expandAllowedWithOrganizationApplications(array $allowed, ?Organization $org): array
    {
        if (! $org || ! is_array($org->enabled_modules) || $org->enabled_modules === []) {
            return $allowed;
        }

        $applications = app(\App\Services\Erp\ApplicationProvisioner::class)
            ->applicationsFromEnabledModules($org->enabled_modules);
        $industry = IndustryRegistry::industryForProfile((string) ($org->deployment_profile ?? ''));

        foreach ($applications as $appId => $on) {
            if (! $on) {
                continue;
            }
            foreach ($this->featureIdsForApplication((string) $appId, $industry) as $featureId) {
                $allowed[$featureId] = true;
            }
        }

        return $allowed;
    }

    /**
     * @return list<string>
     */
    public function featureIdsForApplication(string $appId, string $industry): array
    {
        $ids = [];
        foreach ($this->forIndustry($industry) as $module) {
            if ((string) ($module['id'] ?? '') !== $appId) {
                continue;
            }
            foreach ($module['features'] ?? [] as $feature) {
                $featureId = trim((string) ($feature['id'] ?? ''));
                if ($featureId !== '') {
                    $ids[] = $featureId;
                }
            }
        }

        return $ids;
    }

    /** @param  array<string, mixed>  $group */
    public function restrictGroup(array $group, string $registryModule, ?array $allowed): ?array
    {
        if ($allowed === null) {
            return $group;
        }

        $features = array_values(array_filter(
            $group['features'] ?? [],
            static function ($feature) use ($registryModule, $allowed): bool {
                if (! is_array($feature)) {
                    return false;
                }

                return isset($allowed[$registryModule.'.'.(string) ($feature['key'] ?? '')]);
            },
        ));

        if ($features === []) {
            return null;
        }

        $group['features'] = $features;

        return $group;
    }

    /** @return array<string, true> */
    public function knownPermissionFeatureIds(): array
    {
        $known = [];
        foreach ($this->all() as $modules) {
            foreach ($modules as $module) {
                if (($module['channel'] ?? null) === 'flags') {
                    continue;
                }
                foreach ($module['features'] ?? [] as $feature) {
                    $known[(string) $feature['id']] = true;
                }
            }
        }

        return $known;
    }

    /** @param  array<string, string>  $flags */
    protected function flagModule(string $id, string $label, string $description, array $flags): array
    {
        $features = [];
        foreach ($flags as $key => $featureLabel) {
            $features[] = [
                'id' => $id.'.'.$key,
                'label' => $featureLabel,
                'group' => $label,
                'flag' => $key,
            ];
        }

        return [
            'id' => $id,
            'label' => $label,
            'description' => $description,
            'channel' => 'flags',
            'features' => $features,
        ];
    }

    /** @param  array<string, mixed>  $definition */
    protected function applicationModule(string $appId, array $definition): array
    {
        $features = [];
        foreach ($definition['registry_modules'] ?? [] as $registryModule) {
            $registryModule = (string) $registryModule;
            $group = config("permission_registry.groups.{$registryModule}");
            if (! is_array($group)) {
                continue;
            }
            $allow = $definition['module_features'][$registryModule] ?? null;
            $allow = is_array($allow) ? array_flip(array_map('strval', $allow)) : null;
            $groupLabel = (string) ($definition['module_labels'][$registryModule] ?? $group['label'] ?? $registryModule);
            foreach ($group['features'] ?? [] as $featureKey => $feature) {
                $featureKey = (string) $featureKey;
                if (is_array($allow) && ! isset($allow[$featureKey])) {
                    continue;
                }
                if (! is_array($feature)) {
                    continue;
                }
                $features[] = [
                    'id' => $registryModule.'.'.$featureKey,
                    'label' => (string) ($feature['label'] ?? $featureKey),
                    'group' => $groupLabel,
                ];
            }
        }

        return [
            'id' => $appId,
            'label' => (string) ($definition['label'] ?? $appId),
            'description' => (string) ($definition['description'] ?? ''),
            'channel' => match ($appId) {
                'pos' => 'external_pos',
                'mobile' => 'mobile',
                'manager' => 'manager',
                default => null,
            },
            'features' => $features,
        ];
    }

    protected function industry(string $industry): string
    {
        return in_array($industry, IndustryRegistry::ids(), true) ? $industry : 'commerce';
    }
}
