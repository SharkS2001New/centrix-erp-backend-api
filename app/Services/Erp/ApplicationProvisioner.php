<?php

namespace App\Services\Erp;

use Illuminate\Validation\ValidationException;

class ApplicationProvisioner
{
    /** @var list<string> */
    protected const SALES_CHILDREN = [
        'sales.pos',
        'sales.mobile',
        'sales.backend',
        'sales.dashboard',
        'sales.reports',
    ];

    /** @var list<string> */
    protected const HOSPITALITY_CHILDREN = [
        'hospitality.bar_pos',
        'hospitality.backend',
        'hospitality.dashboard',
        'hospitality.reports',
    ];

    /** @var list<string> */
    protected const DISTRIBUTION_MODULE_KEYS = [
        'distribution',
        'distribution.dashboard',
        'distribution.reports',
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_values(config('erp_applications.order', []));
    }

    /** @return list<array{id: string, label: string, description: string, icon: string}> */
    public function optionsPayload(): array
    {
        $definitions = config('erp_applications.definitions', []);
        $out = [];

        foreach (self::ids() as $id) {
            $meta = $definitions[$id] ?? [];
            $out[] = [
                'id' => $id,
                'label' => (string) ($meta['label'] ?? $id),
                'description' => (string) ($meta['description'] ?? ''),
                'icon' => (string) ($meta['icon'] ?? 'app'),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $enabledModules
     * @return array<string, bool>
     */
    public function applicationsFromEnabledModules(array $enabledModules): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $out[$id] = $this->isApplicationEnabled($id, $enabledModules);
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $applications
     * @return array<string, bool>
     */
    public function enabledModulesFromApplications(array $applications, bool $mobileOrdersEnabled = true): array
    {
        $applications = $this->fillApplicationDefaults($this->sanitizeApplications($applications));
        $modules = [];

        if ($applications['pos']) {
            $modules = $this->mergeModulePatch($modules, $this->enablePatch('pos'));
        } elseif ($applications['backoffice']) {
            $modules = $this->mergeModulePatch($modules, $this->enablePatch('backoffice'));
        }

        if (! $applications['pos']) {
            $modules = $this->mergeModulePatch($modules, $this->disablePatch('pos'));
        }

        if (! $applications['backoffice'] && ! $applications['pos']) {
            $modules = $this->mergeModulePatch($modules, $this->disablePatch('backoffice'));
            $modules = $this->applyDomainDisableRules($modules, $this->disablePatch('backoffice'));
        }

        if ($applications['hotel_bar_pos']) {
            $modules = $this->mergeModulePatch($modules, $this->enablePatch('hotel_bar_pos'));
        } elseif ($applications['hospitality_backoffice']) {
            $modules = $this->mergeModulePatch($modules, $this->enablePatch('hospitality_backoffice'));
        }

        if (! $applications['hotel_bar_pos']) {
            $modules = $this->mergeModulePatch($modules, $this->disablePatch('hotel_bar_pos'));
        }

        if (! $applications['hospitality_backoffice'] && ! $applications['hotel_bar_pos']) {
            $modules = $this->mergeModulePatch($modules, $this->disablePatch('hospitality_backoffice'));
            $modules = $this->applyDomainDisableRules($modules, $this->disablePatch('hospitality_backoffice'));
        }

        foreach (['distribution', 'accounting', 'hr', 'admin'] as $id) {
            if ($applications[$id]) {
                $modules = $this->mergeModulePatch($modules, $this->enablePatch($id));
            } else {
                $modules = $this->mergeModulePatch($modules, $this->disablePatch($id));
                $modules = $this->applyDomainDisableRules($modules, $this->disablePatch($id));
            }
        }

        $modules = $this->syncSalesDomain($modules);
        $modules = $this->syncHospitalityDomain($modules);

        $distributionPatch = $applications['distribution']
            ? ['distribution' => true]
            : array_fill_keys(self::DISTRIBUTION_MODULE_KEYS, false);

        $modules = $this->applyDistributionMobileRules($modules, $distributionPatch, $mobileOrdersEnabled);

        return ModuleRegistry::cascade($modules);
    }

    /**
     * @param  array<string, bool>  $profileModules
     * @return array<string, bool>
     */
    public function applicationsFromProfileModules(array $profileModules): array
    {
        $cascaded = ModuleRegistry::cascade(ModuleRegistry::sanitizeModuleMap($profileModules));

        return $this->applicationsFromEnabledModules($cascaded);
    }

    /**
     * @param  array<string, bool>  $applications
     * @return array<string, bool>
     */
    public function sanitizeApplications(array $applications): array
    {
        $unknown = array_diff(array_keys($applications), self::ids());
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'applications' => ['Unknown application keys: '.implode(', ', $unknown)],
            ]);
        }

        $out = [];
        foreach (self::ids() as $id) {
            if (array_key_exists($id, $applications)) {
                $out[$id] = (bool) $applications[$id];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $applications
     * @return array<string, bool>
     */
    protected function fillApplicationDefaults(array $applications): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $out[$id] = (bool) ($applications[$id] ?? false);
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $enabledModules
     */
    protected function isApplicationEnabled(string $applicationId, array $enabledModules): bool
    {
        return match ($applicationId) {
            'pos' => (bool) ($enabledModules['sales.pos'] ?? false),
            'backoffice' => (bool) ($enabledModules['sales.backend'] ?? false)
                || (bool) ($enabledModules['inventory'] ?? false)
                || (bool) ($enabledModules['customers_suppliers'] ?? false),
            'hotel_bar_pos' => (bool) ($enabledModules['hospitality.bar_pos'] ?? false),
            'hospitality_backoffice' => (bool) ($enabledModules['hospitality.backend'] ?? false)
                || (bool) ($enabledModules['hospitality.dashboard'] ?? false),
            'distribution' => (bool) ($enabledModules['distribution'] ?? false),
            'accounting' => (bool) ($enabledModules['accounting'] ?? false)
                || (bool) ($enabledModules['payments'] ?? false),
            'hr' => (bool) ($enabledModules['hr_payroll'] ?? false),
            'admin' => (bool) ($enabledModules['admin'] ?? false),
            default => false,
        };
    }

    /**
     * @param  array<string, bool>  $modules
     * @param  array<string, bool>  $patch
     * @return array<string, bool>
     */
    protected function mergeModulePatch(array $modules, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $modules[$key] = $value;
        }

        return $modules;
    }

    /** @return array<string, bool> */
    protected function enablePatch(string $applicationId): array
    {
        return match ($applicationId) {
            'pos' => array_merge(
                [
                    'sales' => true,
                    'sales.pos' => true,
                ],
                $this->enablePatch('backoffice'),
            ),
            'backoffice' => [
                'sales' => true,
                'sales.backend' => true,
                'sales.dashboard' => true,
                'sales.reports' => true,
                'inventory' => true,
                'inventory.dashboard' => true,
                'inventory.reports' => true,
                'customers_suppliers' => true,
                'customers_suppliers.reports' => true,
            ],
            // Hotel POS enables hospitality backoffice + shared inventory (products/stock only).
            // Does NOT enable sales.pos / temporary_carts / sales tables.
            'hotel_bar_pos' => array_merge(
                [
                    'hospitality' => true,
                    'hospitality.bar_pos' => true,
                ],
                $this->enablePatch('hospitality_backoffice'),
            ),
            'hospitality_backoffice' => [
                'hospitality' => true,
                'hospitality.backend' => true,
                'hospitality.dashboard' => true,
                'hospitality.reports' => true,
            ],
            'distribution' => [
                'distribution' => true,
                'distribution.dashboard' => true,
                'distribution.reports' => true,
            ],
            'accounting' => [
                'accounting' => true,
                'payments' => true,
                'accounting.dashboard' => true,
                'accounting.reports' => true,
            ],
            'hr' => [
                'hr_payroll' => true,
                'hr_payroll.dashboard' => true,
                'hr_payroll.reports' => true,
            ],
            'admin' => ['admin' => true],
            default => [],
        };
    }

    /** @return array<string, bool> */
    protected function disablePatch(string $applicationId): array
    {
        return match ($applicationId) {
            'pos' => ['sales.pos' => false],
            'backoffice' => [
                'sales.backend' => false,
                'sales.dashboard' => false,
                'sales.reports' => false,
                'inventory' => false,
                'inventory.dashboard' => false,
                'inventory.reports' => false,
                'customers_suppliers' => false,
                'customers_suppliers.dashboard' => false,
                'customers_suppliers.reports' => false,
            ],
            'hotel_bar_pos' => ['hospitality.bar_pos' => false],
            'hospitality_backoffice' => [
                'hospitality' => false,
                'hospitality.backend' => false,
                'hospitality.dashboard' => false,
                'hospitality.reports' => false,
            ],
            'distribution' => [
                'distribution' => false,
                'distribution.dashboard' => false,
                'distribution.reports' => false,
            ],
            'accounting' => [
                'accounting' => false,
                'payments' => false,
                'accounting.dashboard' => false,
                'accounting.reports' => false,
            ],
            'hr' => [
                'hr_payroll' => false,
                'hr_payroll.dashboard' => false,
                'hr_payroll.reports' => false,
            ],
            'admin' => ['admin' => false],
            default => [],
        };
    }

    /**
     * @param  array<string, bool>  $modules
     * @param  array<string, bool>  $patch
     * @return array<string, bool>
     */
    protected function applyDomainDisableRules(array $modules, array $patch): array
    {
        foreach (ModuleRegistry::domainRoots() as $domain) {
            if (! array_key_exists($domain, $patch) || $patch[$domain] !== false) {
                continue;
            }

            $modules[$domain] = false;
            foreach (ModuleRegistry::descendantKeys($domain) as $child) {
                $modules[$child] = false;
            }
        }

        return $modules;
    }

    /**
     * @param  array<string, bool>  $modules
     * @param  array<string, bool>  $patch
     * @return array<string, bool>
     */
    protected function applyDistributionMobileRules(
        array $modules,
        array $patch,
        bool $mobileOrdersEnabled,
    ): array {
        $distributionTouched = false;
        foreach (self::DISTRIBUTION_MODULE_KEYS as $key) {
            if (array_key_exists($key, $patch)) {
                $distributionTouched = true;
                break;
            }
        }

        $distributionOn = false;
        foreach (self::DISTRIBUTION_MODULE_KEYS as $key) {
            if ($modules[$key] ?? false) {
                $distributionOn = true;
                break;
            }
        }

        if ($distributionTouched && $distributionOn) {
            $modules['sales.mobile'] = true;
            $modules['sales'] = true;
        }

        if (! ($modules['sales.mobile'] ?? false) || ! $mobileOrdersEnabled) {
            foreach (self::DISTRIBUTION_MODULE_KEYS as $key) {
                $modules[$key] = false;
            }
        }

        return $modules;
    }

    /** @param  array<string, bool>  $modules */
    protected function syncSalesDomain(array $modules): array
    {
        $modules['sales'] = collect(self::SALES_CHILDREN)
            ->contains(fn (string $key) => (bool) ($modules[$key] ?? false));

        return $modules;
    }

    /** @param  array<string, bool>  $modules */
    protected function syncHospitalityDomain(array $modules): array
    {
        $modules['hospitality'] = collect(self::HOSPITALITY_CHILDREN)
            ->contains(fn (string $key) => (bool) ($modules[$key] ?? false));

        return $modules;
    }
}
