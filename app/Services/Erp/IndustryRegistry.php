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
        $industry = self::industryForProfile($profileKey);
        $ids = config("erp_industries.definitions.{$industry}.permission_application_ids", []);

        return is_array($ids) ? array_values($ids) : [];
    }
}
