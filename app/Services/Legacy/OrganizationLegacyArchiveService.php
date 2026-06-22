<?php

namespace App\Services\Legacy;

use App\Models\Organization;
use Illuminate\Support\Arr;

class OrganizationLegacyArchiveService
{
    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return config('erp.module_settings_defaults.legacy_archive', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $org): array
    {
        $stored = is_array($org->module_settings['legacy_archive'] ?? null)
            ? $org->module_settings['legacy_archive']
            : [];

        return $this->normalize(array_merge($this->defaults(), $stored));
    }

    public function isEnabled(Organization $org): bool
    {
        return (bool) ($this->forOrganization($org)['enabled'] ?? false);
    }

    public function isConfigured(Organization $org): bool
    {
        $settings = $this->forOrganization($org);

        return $this->isEnabled($org)
            && filled($settings['database'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateOrganization(Organization $org, array $input): Organization
    {
        $current = $this->forOrganization($org);
        $merged = $this->normalize(array_merge($current, Arr::only($input, [
            'enabled',
            'database',
            'host',
            'port',
            'username',
            'password',
            'label',
            'cutover_date',
        ])));

        if (array_key_exists('password', $input) && blank($input['password'])) {
            unset($merged['password']);
            if (filled($current['password'] ?? null)) {
                $merged['password'] = $current['password'];
            }
        }

        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['legacy_archive'] = $merged;
        $org->forceFill(['module_settings' => $moduleSettings])->save();

        return $org->fresh();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function maskForClient(array $settings): array
    {
        $masked = $this->normalize($settings);
        $masked['password'] = filled($masked['password'] ?? null) ? '********' : null;
        $masked['password_configured'] = filled($settings['password'] ?? null);

        return $masked;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function normalize(array $settings): array
    {
        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'database' => filled($settings['database'] ?? null) ? (string) $settings['database'] : null,
            'host' => filled($settings['host'] ?? null) ? (string) $settings['host'] : null,
            'port' => isset($settings['port']) && $settings['port'] !== '' ? (int) $settings['port'] : null,
            'username' => filled($settings['username'] ?? null) ? (string) $settings['username'] : null,
            'password' => filled($settings['password'] ?? null) ? (string) $settings['password'] : null,
            'label' => (string) ($settings['label'] ?? 'LightStores archive'),
            'cutover_date' => filled($settings['cutover_date'] ?? null) ? (string) $settings['cutover_date'] : null,
        ];
    }
}
