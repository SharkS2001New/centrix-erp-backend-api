<?php

namespace App\Services\Erp;

class ModuleRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    protected static ?array $modules = null;

    /** @return array<string, array<string, mixed>> */
    public static function modules(): array
    {
        if (self::$modules === null) {
            $tree = config('erp_module_tree', []);
            foreach (['report_modules', 'backoffice_finance_reports'] as $configOnlyKey) {
                unset($tree[$configOnlyKey]);
            }
            self::$modules = $tree;
        }

        return self::$modules;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::modules());
    }

    /** @return list<string> */
    public static function domainRoots(): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $key) => (self::modules()[$key]['kind'] ?? null) === 'domain',
        ));
    }

    public static function parentKey(string $moduleKey): ?string
    {
        $meta = self::modules()[$moduleKey] ?? null;
        if (! $meta) {
            return null;
        }

        return isset($meta['parent']) ? (string) $meta['parent'] : null;
    }

    /** @return list<string> */
    public static function children(string $domainKey): array
    {
        $meta = self::modules()[$domainKey] ?? [];

        return array_values($meta['children'] ?? []);
    }

    /** @return list<string> */
    public static function descendantKeys(string $domainKey): array
    {
        $out = [];
        foreach (self::children($domainKey) as $child) {
            $out[] = $child;
            if ((self::modules()[$child]['kind'] ?? null) === 'domain') {
                $out = array_merge($out, self::descendantKeys($child));
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function reportModuleKeys(): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $key) => (self::modules()[$key]['kind'] ?? null) === 'reports',
        ));
    }

    public static function reportModuleForSlug(string $slug): ?string
    {
        $map = config('erp_module_tree.report_modules', []);

        return isset($map[$slug]) ? (string) $map[$slug] : null;
    }

    /** @return list<string> */
    public static function reportAccessModulesForSlug(string $slug): array
    {
        $backofficeFinance = config('erp_module_tree.backoffice_finance_reports', []);
        if (in_array($slug, $backofficeFinance, true)) {
            return ['sales.reports', 'accounting.reports'];
        }

        $primary = self::reportModuleForSlug($slug);

        return $primary !== null ? [$primary] : [];
    }

    /**
     * Expand legacy top-level `reports` flag into per-domain report modules.
     *
     * @param  array<string, bool>  $modules
     * @return array<string, bool>
     */
    public static function expandLegacyModules(array $modules): array
    {
        if (! array_key_exists('reports', $modules)) {
            return $modules;
        }

        $legacy = (bool) $modules['reports'];
        unset($modules['reports']);

        foreach (self::reportModuleKeys() as $key) {
            if (! array_key_exists($key, $modules)) {
                $modules[$key] = $legacy;
            }
        }

        return $modules;
    }

    /**
     * Domain off disables all descendants. Domain on keeps explicit child overrides
     * (e.g. sales.pos off while sales.backend stays on for backoffice-only tenants).
     *
     * @param  array<string, bool>  $modules
     * @return array<string, bool>
     */
    public static function cascade(array $modules): array
    {
        $modules = self::expandLegacyModules($modules);
        $input = $modules;

        foreach (self::domainRoots() as $domain) {
            $children = self::descendantKeys($domain);
            $explicitChildKeys = array_values(array_filter(
                $children,
                fn (string $child) => array_key_exists($child, $input),
            ));

            $anyChildOn = false;
            foreach ($children as $child) {
                if ((bool) ($modules[$child] ?? false)) {
                    $anyChildOn = true;
                }
            }

            $domainOn = (bool) ($modules[$domain] ?? false) || $anyChildOn;

            if (! $domainOn) {
                $modules[$domain] = false;
                foreach ($children as $child) {
                    $modules[$child] = false;
                }

                continue;
            }

            $modules[$domain] = true;

            $domainExplicitlyOn = array_key_exists($domain, $input) && (bool) $input[$domain];
            if ($domainExplicitlyOn && $explicitChildKeys === []) {
                foreach ($children as $child) {
                    $modules[$child] = true;
                }

                continue;
            }

            foreach ($children as $child) {
                if (! array_key_exists($child, $modules)) {
                    $modules[$child] = false;
                }
            }
        }

        return self::applyModuleDependencies($modules);
    }

    /**
     * Cross-domain rules outside the parent/child tree.
     *
     * @param  array<string, bool>  $modules
     * @return array<string, bool>
     */
    public static function applyModuleDependencies(array $modules): array
    {
        // Distribution requires mobile field sales; mobile alone is valid without distribution.
        if (! ($modules['sales.mobile'] ?? false)) {
            $modules['distribution'] = false;
            foreach (self::descendantKeys('distribution') as $child) {
                $modules[$child] = false;
            }
        }

        return $modules;
    }

    /** @return array<int, array{key: string, label: string, nav_group: string, kind: string, parent: string|null, children: list<string>}> */
    public static function optionsPayload(): array
    {
        return array_map(function (string $key, array $meta) {
            return [
                'key' => $key,
                'label' => $meta['label'] ?? $key,
                'nav_group' => $meta['nav_group'] ?? 'Other',
                'kind' => $meta['kind'] ?? 'feature',
                'parent' => $meta['parent'] ?? null,
                'children' => array_values($meta['children'] ?? []),
            ];
        }, array_keys(self::modules()), array_values(self::modules()));
    }
}
