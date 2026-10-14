<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformBillingSetting;
use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\BackofficeSections;
use App\Services\Erp\IndustryRegistry;
use Illuminate\Support\Facades\Schema;

/**
 * Package 1 access. Stored on platform billing settings, applied only while
 * the organisation's access mode is free. It does not rewrite enabled_modules.
 */
class FreePlanAccess
{
    /** @return array<string, array{page_keys: list<string>}> */
    public function normalize(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($this->industries() as $industry => $definition) {
            $incoming = is_array($raw[$industry] ?? null) ? $raw[$industry] : [];
            $known = $this->pageIds($definition);
            $picked = [];
            foreach ((array) ($incoming['page_keys'] ?? []) as $key) {
                $id = strtolower(trim((string) $key));
                if ($id !== '' && in_array($id, $known, true) && ! in_array($id, $picked, true)) {
                    $picked[] = $id;
                }
            }
            if ($picked === []) {
                $picked = array_values(array_intersect((array) ($definition['default'] ?? ['dashboard']), $known));
            }
            if ($picked === []) {
                $picked = array_slice($known, 0, 1);
            }
            $out[$industry] = ['page_keys' => $picked];
        }

        return $out;
    }

    /** @return array<string, array{page_keys: list<string>}> */
    public function stored(): array
    {
        if (! Schema::hasTable('platform_billing_settings') || ! Schema::hasColumn('platform_billing_settings', 'free_plan_access')) {
            return $this->normalize([]);
        }

        $raw = PlatformBillingSetting::current()?->free_plan_access;

        return $this->normalize(is_array($raw) ? $raw : []);
    }

    /** @param  array<string, mixed>  $incoming */
    public function persist(PlatformBillingSetting $settings, array $incoming): array
    {
        $normalized = $this->normalize($incoming);
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
        $pageKeys = $this->normalize([$industry => $selection ?? []])[$industry]['page_keys'];
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

        return [
            'industry' => $industry,
            'page_keys' => $pageKeys,
            'workspace_keys' => array_values(array_filter((array) ($definition['workspaces'] ?? []))),
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
            'name' => (string) config('free_plan_access.name', 'Package 1'),
            'industry' => $grant['industry'],
            'page_keys' => $grant['page_keys'],
            'pages' => $grant['pages'],
            'rules' => $grant['rules'],
        ];
    }

    public function allowsRoute(?Organization $org, string $path): bool
    {
        $industry = $org ? $this->industryFor($org) : 'commerce';
        $grant = $this->grantFor($industry, $this->stored()[$industry] ?? []);

        return $this->allowsPath($path, $grant);
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
        $grant = $this->grantFor($this->industryFor($org), $this->stored()[$this->industryFor($org)] ?? []);
        $applications = array_fill_keys(ApplicationProvisioner::ids(), false);
        foreach ($grant['workspace_keys'] as $id) {
            if (array_key_exists($id, $applications)) {
                $applications[$id] = true;
            }
        }
        $payload['modules'] = app(ApplicationProvisioner::class)
            ->enabledModulesFromApplications($applications, false);

        if ($grant['industry'] === 'commerce') {
            $sections = array_fill_keys(BackofficeSections::SECTION_KEYS, false);
            foreach ($grant['sections'] as $section) {
                if (array_key_exists($section, $sections)) {
                    $sections[$section] = true;
                }
            }
            $settings = is_array($payload['module_settings'] ?? null) ? $payload['module_settings'] : [];
            $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];
            $sales['backoffice_sections'] = $sections;
            $settings['sales'] = $sales;
            $payload['module_settings'] = $settings;
        }

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

        return $payload;
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
}
