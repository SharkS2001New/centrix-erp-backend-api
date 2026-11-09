<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformBillingSetting;
use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\BackofficeSections;
use App\Services\Erp\IndustryRegistry;
use App\Support\SchemaProbe;
use Illuminate\Support\Facades\Schema;

/**
 * Package 1 access. Stored on platform billing settings, applied only while
 * the organisation's access mode is free. It does not rewrite enabled_modules.
 */
class FreePlanAccess
{
    /** @return array<string, array{page_keys: list<string>, workspace_keys: list<string>, feature_keys: array<string, list<string>>}> */
    public function normalize(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $catalog = app(PackageFeatureCatalog::class);
        $out = [];
        foreach ($this->industries() as $industry => $definition) {
            $incoming = is_array($raw[$industry] ?? null) ? $raw[$industry] : [];
            $known = $this->pageIds($definition);
            $explicitFeatures = array_key_exists('feature_keys', $incoming) && is_array($incoming['feature_keys']);
            if ($explicitFeatures) {
                $workspaceKeys = array_values(array_unique(array_merge(
                    array_map('strval', array_keys($incoming['feature_keys'])),
                    array_map('strval', (array) ($incoming['workspace_keys'] ?? [])),
                )));
                $features = $catalog->normalize($industry, $workspaceKeys, $incoming['feature_keys']);
                $picked = $this->pagesForFeatures($industry, $features);
            } else {
                $picked = [];
                foreach ((array) ($incoming['page_keys'] ?? []) as $key) {
                    $id = strtolower(trim((string) $key));
                    if ($id !== '' && in_array($id, $known, true) && ! in_array($id, $picked, true)) {
                        $picked[] = $id;
                    }
                }
                $features = $picked === [] ? [] : $this->featuresForPages($industry, $picked);
            }
            if ($picked === []) {
                $picked = array_values(array_intersect((array) ($definition['default'] ?? ['dashboard']), $known));
            }
            if ($picked === []) {
                $picked = array_slice($known, 0, 1);
            }
            if ($features === []) {
                $features = $this->featuresForPages($industry, $picked);
            }
            $workspaces = array_keys($features);
            if ($workspaces === []) {
                $workspaces = array_values(array_filter((array) ($definition['workspaces'] ?? [])));
            }
            $out[$industry] = [
                'page_keys' => $picked,
                'workspace_keys' => array_values($workspaces),
                'feature_keys' => $features,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        try {
            $request = request();
            if ($request->attributes->has('free_plan_access.stored')) {
                return $request->attributes->get('free_plan_access.stored');
            }
        } catch (\Throwable) {
            $request = null;
        }

        if (! SchemaProbe::hasTable('platform_billing_settings') || ! SchemaProbe::hasColumn('platform_billing_settings', 'free_plan_access')) {
            $out = $this->normalize([]);
            $out['name'] = $this->name([]);
            if ($request) {
                $request->attributes->set('free_plan_access.stored', $out);
            }

            return $out;
        }

        $raw = PlatformBillingSetting::current()?->free_plan_access;
        $raw = is_array($raw) ? $raw : [];
        $out = $this->normalize($raw);
        $out['name'] = $this->name($raw);
        if ($request) {
            $request->attributes->set('free_plan_access.stored', $out);
        }

        return $out;
    }

    /** Display name for the free plan card. Defaults to Package 1. */
    public function name(?array $raw = null): string
    {
        if ($raw === null) {
            if (! SchemaProbe::hasTable('platform_billing_settings') || ! SchemaProbe::hasColumn('platform_billing_settings', 'free_plan_access')) {
                return (string) config('free_plan_access.name', 'Package 1');
            }
            $raw = PlatformBillingSetting::current()?->free_plan_access;
            $raw = is_array($raw) ? $raw : [];
        }
        $name = trim((string) ($raw['name'] ?? ''));

        return $name !== '' ? $name : (string) config('free_plan_access.name', 'Package 1');
    }

    /** @param  array<string, mixed>  $incoming */
    public function persist(PlatformBillingSetting $settings, array $incoming): array
    {
        $normalized = $this->normalize($incoming);
        $normalized['name'] = $this->name($incoming);
        if (Schema::hasColumn('platform_billing_settings', 'free_plan_access')) {
            $settings->free_plan_access = $normalized;
            $settings->save();
        }

        return $normalized;
    }

    /** @return list<array{id: string, label: string, pages: list<array{id: string, label: string}>}> */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->industries() as $industry => $definition) {
            $pages = [];
            foreach ((array) ($definition['pages'] ?? []) as $page) {
                if (! is_array($page) || empty($page['id'])) {
                    continue;
                }
                $pages[] = [
                    'id' => (string) $page['id'],
                    'label' => (string) ($page['label'] ?? $page['id']),
                ];
            }
            $out[] = [
                'id' => $industry,
                'label' => (string) ($definition['label'] ?? $industry),
                'pages' => $pages,
            ];
        }

        return $out;
    }

    /**
     * @param  array{page_keys?: list<string>}|null  $selection
     * @return array{industry: string, page_keys: list<string>, workspace_keys: list<string>, pages: list<array{id: string, label: string}>, rules: list<array{path: string, exact: bool}>, api: list<string>, sections: list<string>}
     */
    public function grantFor(string $industry, ?array $selection = null): array
    {
        $industry = $this->knownIndustry($industry);
        $definition = $this->industries()[$industry];
        $stored = $this->normalize([$industry => $selection ?? []])[$industry];
        $pageKeys = $stored['page_keys'];
        $pages = [];
        $rules = [];
        $api = [];
        $sections = [];
        foreach ((array) ($definition['pages'] ?? []) as $page) {
            if (! is_array($page) || ! in_array((string) ($page['id'] ?? ''), $pageKeys, true)) {
                continue;
            }
            $pages[] = [
                'id' => (string) $page['id'],
                'label' => (string) ($page['label'] ?? $page['id']),
            ];
            foreach ((array) ($page['exact'] ?? []) as $path) {
                $rules[] = ['path' => (string) $path, 'exact' => true];
            }
            foreach ((array) ($page['paths'] ?? []) as $path) {
                $rules[] = ['path' => (string) $path, 'exact' => false];
            }
            foreach ((array) ($page['api'] ?? []) as $prefix) {
                $api[] = trim((string) $prefix, '/');
            }
            if (! empty($page['section'])) {
                $sections[] = (string) $page['section'];
            }
        }

        foreach ($this->moduleApiPrefixes($stored['workspace_keys'] ?? []) as $prefix) {
            $api[] = $prefix;
        }

        return [
            'industry' => $industry,
            'page_keys' => $pageKeys,
            'workspace_keys' => array_values($stored['workspace_keys'] ?? []),
            'feature_keys' => $stored['feature_keys'] ?? [],
            'pages' => $pages,
            'rules' => $rules,
            'api' => array_values(array_unique($api)),
            'sections' => array_values(array_unique($sections)),
        ];
    }

    /** @return array<string, mixed> */
    public function publicGrant(?Organization $org): array
    {
        $industry = $org ? $this->industryFor($org) : 'commerce';
        $grant = $this->grantFor($industry, $this->stored()[$industry] ?? []);

        return [
            'name' => $this->name(),
            'industry' => $grant['industry'],
            'page_keys' => $grant['page_keys'],
            'pages' => $grant['pages'],
            'rules' => $grant['rules'],
        ];
    }

    public function allowsRoute(?Organization $org, string $path): bool
    {
        $grant = $org
            ? $this->grantForOrganization($org)
            : $this->grantFor('commerce', $this->stored()['commerce'] ?? []);

        return $this->allowsPath($path, $grant);
    }

    /**
     * Package 1 grant expanded with applications the organisation has enabled.
     * Setup type / free plan remain the baseline; org customization stays effective.
     *
     * @return array{industry: string, page_keys: list<string>, workspace_keys: list<string>, feature_keys: array<string, list<string>>, pages: list<array{id: string, label: string}>, rules: list<array{path: string, exact: bool}>, api: list<string>, sections: list<string>}
     */
    public function grantForOrganization(Organization $org): array
    {
        $industry = $this->industryFor($org);
        $grant = $this->grantFor($industry, $this->stored()[$industry] ?? []);
        $orgApps = $this->organizationWorkspaceKeys($org);
        if ($orgApps === []) {
            return $grant;
        }

        $workspaceKeys = array_values(array_unique(array_merge($grant['workspace_keys'], $orgApps)));
        $featureKeys = is_array($grant['feature_keys'] ?? null) ? $grant['feature_keys'] : [];
        $catalog = app(PackageFeatureCatalog::class);
        foreach ($orgApps as $appId) {
            if (array_key_exists($appId, $featureKeys) && $featureKeys[$appId] !== []) {
                continue;
            }
            $ids = $catalog->featureIdsForApplication($appId, $industry);
            if ($ids !== []) {
                $featureKeys[$appId] = $ids;
            }
        }

        $pages = $this->pagesForFeatures($industry, $featureKeys);
        if ($pages === []) {
            $pages = $grant['page_keys'];
        }

        $definition = $this->industries()[$industry] ?? [];
        $pageRows = [];
        $rules = [];
        $api = [];
        $sections = [];
        foreach ((array) ($definition['pages'] ?? []) as $page) {
            if (! is_array($page) || ! in_array((string) ($page['id'] ?? ''), $pages, true)) {
                continue;
            }
            $pageRows[] = [
                'id' => (string) $page['id'],
                'label' => (string) ($page['label'] ?? $page['id']),
            ];
            foreach ((array) ($page['exact'] ?? []) as $path) {
                $rules[] = ['path' => (string) $path, 'exact' => true];
            }
            foreach ((array) ($page['paths'] ?? []) as $path) {
                $rules[] = ['path' => (string) $path, 'exact' => false];
            }
            foreach ((array) ($page['api'] ?? []) as $prefix) {
                $api[] = trim((string) $prefix, '/');
            }
            if (! empty($page['section'])) {
                $sections[] = (string) $page['section'];
            }
        }
        foreach ($this->moduleApiPrefixes($workspaceKeys) as $prefix) {
            $api[] = $prefix;
        }

        return [
            'industry' => $industry,
            'page_keys' => array_values($pages),
            'workspace_keys' => $workspaceKeys,
            'feature_keys' => $featureKeys,
            'pages' => $pageRows,
            'rules' => $rules,
            'api' => array_values(array_unique($api)),
            'sections' => array_values(array_unique($sections)),
        ];
    }

    /**
     * @return list<string>
     */
    public function organizationWorkspaceKeys(Organization $org): array
    {
        if (! is_array($org->enabled_modules) || $org->enabled_modules === []) {
            return [];
        }

        $applications = app(ApplicationProvisioner::class)
            ->applicationsFromEnabledModules($org->enabled_modules);

        return array_values(array_keys(array_filter($applications)));
    }

    /** @param  array{api?: list<string>}  $grant */
    public function allowsPath(string $path, array $grant): bool
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'api/v1/')) {
            $path = substr($path, strlen('api/v1/'));
        }
        $path = trim($path, '/');

        if ($path === '' || str_starts_with($path, 'auth/')) {
            return true;
        }

        foreach ([
            'erp/capabilities',
            'erp/organization/profile',
            'erp/organization/logo',
            'erp/organization/subscription',
            'erp/organization/licence',
            'erp/organization/billing',
            'notifications',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        foreach ((array) ($grant['api'] ?? []) as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hide everything Package 1 does not include. The organisation record is left as it was.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function maskCapabilities(Organization $org, array $payload): array
    {
        // Union Package 1 with org-configured applications so platform customizations stick.
        $grant = $this->grantForOrganization($org);
        $applications = array_fill_keys(ApplicationProvisioner::ids(), false);
        foreach ($grant['workspace_keys'] as $id) {
            if (array_key_exists($id, $applications)) {
                $applications[$id] = true;
            }
        }
        $payload['modules'] = app(ApplicationProvisioner::class)
            ->enabledModulesFromApplications($applications, false);

        $settings = is_array($payload['module_settings'] ?? null) ? $payload['module_settings'] : [];
        $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];
        $sales['enable_mobile_orders'] = in_array('mobile', $grant['workspace_keys'], true);
        $sales['enable_manager_app'] = in_array('manager', $grant['workspace_keys'], true);

        if ($grant['industry'] === 'commerce') {
            $sections = array_fill_keys(BackofficeSections::SECTION_KEYS, false);
            $fromFeatures = app(PackageFeatureCatalog::class)->backofficeSections($grant['feature_keys'] ?? []);
            $sectionIds = $fromFeatures !== null ? $fromFeatures : $grant['sections'];
            foreach ($sectionIds as $section) {
                if (array_key_exists($section, $sections)) {
                    $sections[$section] = true;
                }
            }
            $sales['backoffice_sections'] = $sections;
        }

        $settings['sales'] = $sales;
        $settings['licence'] = [
            'plan_id' => null,
            'modules' => $grant['workspace_keys'],
            'features' => $grant['feature_keys'] ?? [],
        ];
        $this->applyFlagModule($settings, 'spa', $grant['feature_keys'] ?? [], 'spa');
        $this->applyFlagModule($settings, 'driving', $grant['feature_keys'] ?? [], 'driving');
        $payload['module_settings'] = $settings;
        $payload['mobile_orders_enabled'] = $sales['enable_mobile_orders'];

        if (isset($payload['workspaces']) && is_array($payload['workspaces'])) {
            $allowed = $grant['workspace_keys'];
            $filtered = array_values(array_filter(
                $payload['workspaces'],
                fn ($workspace) => is_array($workspace) && in_array((string) ($workspace['id'] ?? ''), $allowed, true),
            ));
            if ($filtered !== []) {
                $payload['workspaces'] = $filtered;
            }
        }

        $channels = [];
        if (
            in_array('backoffice', $grant['workspace_keys'], true)
            || in_array('hospitality_backoffice', $grant['workspace_keys'], true)
            || in_array('spa', $grant['workspace_keys'], true)
            || in_array('driving', $grant['workspace_keys'], true)
            || in_array('admin', $grant['workspace_keys'], true)
            || in_array('accounting', $grant['workspace_keys'], true)
            || in_array('hr', $grant['workspace_keys'], true)
        ) {
            $channels[] = 'backoffice';
        }
        if (in_array('pos', $grant['workspace_keys'], true) || in_array('hotel_bar_pos', $grant['workspace_keys'], true)) {
            $channels[] = 'pos';
        }
        if (in_array('mobile', $grant['workspace_keys'], true)) {
            $channels[] = 'mobile';
        }
        if (in_array('manager', $grant['workspace_keys'], true)) {
            $channels[] = 'manager';
        }
        $payload['allowed_login_channels'] = $channels !== [] ? $channels : ['backoffice'];
        $payload['channels'] = array_values(array_filter([
            in_array('pos', $grant['workspace_keys'], true) ? 'pos' : null,
            in_array('mobile', $grant['workspace_keys'], true) ? 'mobile' : null,
            in_array('backoffice', $grant['workspace_keys'], true) ? 'backend' : null,
        ]));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, list<string>>  $featureKeys
     */
    protected function applyFlagModule(array &$settings, string $moduleId, array $featureKeys, string $industry): void
    {
        if (! array_key_exists($moduleId, $featureKeys)) {
            $section = is_array($settings[$moduleId] ?? null) ? $settings[$moduleId] : [];
            $section['features'] = [];
            foreach (app(PackageFeatureCatalog::class)->forIndustry($industry) as $module) {
                if (($module['id'] ?? '') !== $moduleId) {
                    continue;
                }
                foreach ($module['features'] ?? [] as $feature) {
                    $flag = (string) ($feature['flag'] ?? '');
                    if ($flag !== '') {
                        $section['features'][$flag] = false;
                    }
                }
            }
            if (($section['features'] ?? []) !== []) {
                $settings[$moduleId] = $section;
            }

            return;
        }

        $selected = array_flip(array_map('strval', $featureKeys[$moduleId]));
        $flags = [];
        foreach (app(PackageFeatureCatalog::class)->forIndustry($industry) as $module) {
            if (($module['id'] ?? '') !== $moduleId) {
                continue;
            }
            foreach ($module['features'] ?? [] as $feature) {
                $flag = (string) ($feature['flag'] ?? '');
                if ($flag === '') {
                    continue;
                }
                $flags[$flag] = isset($selected[(string) $feature['id']]);
            }
        }
        $section = is_array($settings[$moduleId] ?? null) ? $settings[$moduleId] : [];
        $section['features'] = $flags;
        $settings[$moduleId] = $section;
    }

    public function industryFor(Organization $org): string
    {
        return $this->knownIndustry(IndustryRegistry::industryForProfile((string) ($org->deployment_profile ?? '')));
    }

    /** @return array<string, array<string, mixed>> */
    protected function industries(): array
    {
        $industries = config('free_plan_access.industries', []);

        return is_array($industries) ? $industries : [];
    }

    protected function knownIndustry(string $industry): string
    {
        $industry = strtolower(trim($industry));

        return isset($this->industries()[$industry]) ? $industry : 'commerce';
    }

    /** @param  array<string, mixed>  $definition
     * @return list<string>
     */
    protected function pageIds(array $definition): array
    {
        $ids = [];
        foreach ((array) ($definition['pages'] ?? []) as $page) {
            if (is_array($page) && ! empty($page['id'])) {
                $ids[] = (string) $page['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, list<string>>  $featureKeys
     * @return list<string>
     */
    protected function pagesForFeatures(string $industry, array $featureKeys): array
    {
        $known = array_flip($this->pageIds($this->industries()[$industry] ?? []));
        $picked = [];
        $add = function (array $ids) use (&$picked, $known): void {
            foreach ($ids as $id) {
                $id = (string) $id;
                if (isset($known[$id]) && ! in_array($id, $picked, true)) {
                    $picked[] = $id;
                }
            }
        };

        foreach ($featureKeys as $moduleId => $ids) {
            foreach ((array) $ids as $featureId) {
                $featureId = (string) $featureId;
                $prefix = explode('.', $featureId, 2)[0];
                $flag = explode('.', $featureId, 2)[1] ?? '';
                if ($industry === 'hospitality' && ($moduleId === 'hotel_bar_pos' || $prefix === 'hotel_bar_pos')) {
                    $add(['hotel_pos']);
                    continue;
                }
                if ($industry === 'hospitality' && $prefix === 'hospitality') {
                    $add(match ($flag) {
                        'dashboard' => ['dashboard'],
                        'rooms', 'reservations', 'frontdesk', 'folios', 'housekeeping', 'outlets' => ['rooms'],
                        'orders', 'payments_breakdown' => ['sales'],
                        'reports' => ['reports'],
                        default => [],
                    });
                    continue;
                }
                if ($industry === 'hospitality' && $prefix === 'catalogue') {
                    $add(['catalogue']);
                    continue;
                }
                if ($industry === 'hospitality' && $prefix === 'inventory') {
                    $add(['stock']);
                    continue;
                }
                if ($industry === 'hospitality' && $prefix === 'purchasing') {
                    $add(['purchasing']);
                    continue;
                }
                if ($moduleId === 'spa' || ($industry === 'spa' && $prefix === 'spa')) {
                    $add([$flag]);
                    continue;
                }
                if ($moduleId === 'driving' || ($industry === 'driving' && $prefix === 'driving')) {
                    $add([$flag === 'fees' ? 'payments' : $flag]);
                    continue;
                }
                $sections = app(PackageFeatureCatalog::class)->backofficeSections([(string) $moduleId => [$featureId]]) ?? [];
                $add($sections);
            }
        }

        return $picked;
    }

    /**
     * @param  list<string>  $pageKeys
     * @return array<string, list<string>>
     */
    protected function featuresForPages(string $industry, array $pageKeys): array
    {
        $wanted = array_flip($pageKeys);
        $out = [];
        foreach (app(PackageFeatureCatalog::class)->forIndustry($industry) as $module) {
            $picked = [];
            foreach ($module['features'] ?? [] as $feature) {
                $pages = $this->pagesForFeatures($industry, [(string) $module['id'] => [(string) $feature['id']]]);
                foreach ($pages as $page) {
                    if (isset($wanted[$page])) {
                        $picked[] = (string) $feature['id'];
                        break;
                    }
                }
            }
            if ($picked !== []) {
                $out[(string) $module['id']] = $picked;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $workspaceKeys
     * @return list<string>
     */
    protected function moduleApiPrefixes(array $workspaceKeys): array
    {
        $map = [
            'pos' => ['pos'],
            'mobile' => ['mobile'],
            'manager' => ['manager'],
            'accounting' => ['accounting'],
            'hr' => ['attendance', 'payroll', 'employees'],
            'distribution' => ['fulfillment'],
            'hotel_bar_pos' => ['hospitality/pos'],
            'admin' => ['branches', 'users'],
        ];
        $out = [];
        foreach ($workspaceKeys as $id) {
            foreach ($map[(string) $id] ?? [] as $prefix) {
                $out[] = $prefix;
            }
        }

        return $out;
    }
}
