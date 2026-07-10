<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Public-disk upload roots are always under one org folder:
 *   orgs/{COMPANY_CODE}/…
 * Platform uploads use the PLATFORM company code the same way.
 */
class OrganizationPublicStorage
{
    public static function sanitizeCode(?string $companyCode): string
    {
        $code = strtoupper(trim((string) $companyCode));
        $code = preg_replace('/[^A-Z0-9_-]+/', '_', $code) ?: '';
        $code = trim($code, '_-');

        return $code !== '' ? $code : 'UNKNOWN';
    }

    public static function resolveOrganization(Organization|int|string|null $org): ?Organization
    {
        if ($org instanceof Organization) {
            return $org;
        }
        if ($org === null || $org === '') {
            return null;
        }

        return Organization::query()->find((int) $org);
    }

    public static function companyCodeFor(Organization|int|string|null $org): string
    {
        $model = self::resolveOrganization($org);
        if ($model?->company_code) {
            return self::sanitizeCode($model->company_code);
        }

        $platform = config('erp.platform_company_code', 'PLATFORM');

        return self::sanitizeCode($platform);
    }

    /** Root folder for an organization, e.g. orgs/PLATFORM */
    public static function root(Organization|int|string|null $org): string
    {
        return 'orgs/'.self::companyCodeFor($org);
    }

    /** @param  string  ...$segments  Path segments under the org root */
    public static function path(Organization|int|string|null $org, string ...$segments): string
    {
        $parts = array_values(array_filter(
            array_map(static fn ($s) => trim(str_replace('\\', '/', (string) $s), '/'), $segments),
            static fn ($s) => $s !== '',
        ));

        return self::root($org).($parts ? '/'.implode('/', $parts) : '');
    }

    public static function platformRoot(): string
    {
        return self::root(self::platformOrganization());
    }

    public static function platformOrganization(): ?Organization
    {
        $code = config('erp.platform_company_code', 'PLATFORM');

        return Organization::query()->where('company_code', $code)->first();
    }

    public static function isOrgScopedPath(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        return str_starts_with($path, 'orgs/')
            || str_starts_with($path, 'organizations/');
    }
}
