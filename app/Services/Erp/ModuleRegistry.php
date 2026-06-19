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
            self::$modules = config('erp_module_tree', []);
            unset(self::$modules['report_modules']);
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
     * When a domain is disabled, force its descendants off. When a child is enabled, ensure its domain is on.
     *
     * @param  array<string, bool>  $modules
     * @return array<string, bool>
     */
    public static function cascade(array $modules): array
    {
        $modules = self::expandLegacyModules($modules);

        foreach (self::domainRoots() as $domain) {
            $children = self::descendantKeys($domain);
            $domainOn = (bool) ($modules[$domain] ?? false);

            if (! $domainOn) {
                foreach ($children as $child) {
                    $modules[$child] = false;
                }
                continue;
            }

            $anyChildOn = false;
            foreach ($children as $child) {
                if ((bool) ($modules[$child] ?? false)) {
                    $anyChildOn = true;
                    break;
                }
            }

            if ($anyChildOn) {
                $modules[$domain] = true;
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
