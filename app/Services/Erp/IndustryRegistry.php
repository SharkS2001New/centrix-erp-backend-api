<?php

namespace App\Services\Erp;

class IndustryRegistry
{
    /** @return list<string> */
    public static function ids(): array
    {
        return array_values(config('erp_industries.order', []));
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $industryId): ?array
    {
        $def = config("erp_industries.definitions.{$industryId}");

        return is_array($def) ? $def : null;
    }

    public static function isHospitality(?string $deploymentProfile): bool
    {
        return self::industryForProfile((string) $deploymentProfile) === 'hospitality';
    }

    public static function industryForProfile(string $profileKey): string
    {
        $profileKey = trim($profileKey);
        // Pre-hospitality tenants and unknown profiles stay on Retail & Distribution.
        if ($profileKey === '') {
            return 'commerce';
        }

        $fromProfile = config("erp.profiles.{$profileKey}.industry");
        if (is_string($fromProfile) && $fromProfile !== '') {
            return $fromProfile;
        }

        foreach (self::ids() as $industryId) {
            $keys = config("erp_industries.definitions.{$industryId}.profile_keys", []);
            if (is_array($keys) && in_array($profileKey, $keys, true)) {
                return $industryId;
            }
        }

        return 'commerce';
    }

    public static function labelForIndustry(string $industryId): string
    {
        return (string) (self::definition($industryId)['label'] ?? $industryId);
    }

    public static function labelForProfile(string $profileKey): string
    {
        return self::labelForIndustry(self::industryForProfile($profileKey));
    }

    /** @return array{id: string, label: string} */
    public static function summaryForOrganization(?string $deploymentProfile): array
    {
        $profile = is_string($deploymentProfile) && $deploymentProfile !== ''
            ? $deploymentProfile
            : 'wholesale_retail';
        $industry = self::industryForProfile($profile);

        return [
            'id' => $industry,
            'label' => self::labelForIndustry($industry),
        ];
    }

    /** @return list<array{id: string, label: string, description: string, default_profile: string, profile_keys: list<string>}> */
    public static function optionsPayload(): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $def = self::definition($id);
            if ($def === null) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'label' => (string) ($def['label'] ?? $id),
                'description' => (string) ($def['description'] ?? ''),
                'default_profile' => (string) ($def['default_profile'] ?? ''),
                'profile_keys' => array_values($def['profile_keys'] ?? []),
                'permission_application_ids' => array_values($def['permission_application_ids'] ?? []),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function permissionApplicationIdsForProfile(string $profileKey): array
    {
        return self::permissionApplicationIdsForIndustry(self::industryForProfile($profileKey));
    }

    /** @return list<string> */
    public static function permissionApplicationIdsForIndustry(string $industryId): array
    {
        $ids = config("erp_industries.definitions.{$industryId}.permission_application_ids", []);

        return is_array($ids) ? array_values($ids) : [];
    }

    /**
     * Registry module keys nested under this industry's permission applications.
     *
     * @return list<string>
     */
    public static function registryModulesForIndustry(string $industryId): array
    {
        $modules = [];
        foreach (self::permissionApplicationIdsForIndustry($industryId) as $appId) {
            $def = config("permission_applications.applications.{$appId}");
            if (! is_array($def)) {
                continue;
            }
            foreach ($def['registry_modules'] ?? [] as $moduleKey) {
                $modules[(string) $moduleKey] = true;
            }
        }

        return array_keys($modules);
    }

    /**
     * Permission codes belonging to registry modules for this industry.
     *
     * @return list<string>
     */
    public static function permissionCodesForIndustry(string $industryId): array
    {
        $allowedModules = array_flip(self::registryModulesForIndustry($industryId));
        $codes = [];

        foreach (config('permission_registry.groups', []) as $moduleKey => $group) {
            if (! isset($allowedModules[$moduleKey])) {
                continue;
            }
            foreach ($group['features'] ?? [] as $featureKey => $feature) {
                foreach ($feature['actions'] ?? [] as $action) {
                    $codes[] = "{$moduleKey}.{$featureKey}.{$action}";
                }
            }
        }

        return $codes;
    }

    /** Union of permission codes across every configured industry. */
    public static function permissionCodesForAllIndustries(): array
    {
        $codes = [];
        foreach (self::ids() as $industryId) {
            foreach (self::permissionCodesForIndustry($industryId) as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }
}
