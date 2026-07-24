<?php

namespace App\Services\Platform;

use App\Models\Organization;

/**
 * Platform-wide payroll calendar rules (super-admin).
 * Default: enforce month-end / grace-window schedule for all tenants.
 */
class PlatformPayrollScheduleSettingsResolver
{
    public const MODULE_KEY = 'platform_payroll';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            /** When true, payroll may only run on month-end (or grace days after). */
            'enforce_month_end_run_schedule' => true,
        ];
    }

    public static function platformOrganization(bool $refresh = false): ?Organization
    {
        static $organization = null;
        static $resolved = false;

        if ($refresh) {
            $resolved = false;
            $organization = null;
        }

        if ($resolved) {
            return $organization;
        }

        $resolved = true;
        $organization = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();

        return $organization;
    }

    /** @return array<string, mixed> */
    public static function forPlatform(): array
    {
        try {
            $org = self::platformOrganization();
            $stored = is_array($org?->module_settings[self::MODULE_KEY] ?? null)
                ? $org->module_settings[self::MODULE_KEY]
                : [];

            return self::normalize(array_merge(self::defaults(), $stored));
        } catch (\Throwable) {
            return self::defaults();
        }
    }

    public static function enforceMonthEndSchedule(): bool
    {
        return (bool) (self::forPlatform()['enforce_month_end_run_schedule'] ?? true);
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $settings = self::forPlatform();

        return [
            'scope' => 'platform',
            'settings' => $settings,
            'effective' => [
                'enforce_month_end_run_schedule' => (bool) $settings['enforce_month_end_run_schedule'],
            ],
            'hints' => [
                'enforce_on' => 'Payroll can only run on the last day of the month, or in the grace window after month end for the prior period.',
                'enforce_off' => 'Tenants may generate payroll for any current or past month at any time. Future months remain blocked.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function save(array $incoming): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(503, 'Platform organization is not configured.');
        }

        $moduleSettings = $org->module_settings ?? [];
        $current = self::forPlatform();
        $moduleSettings[self::MODULE_KEY] = self::normalize(array_merge($current, $incoming));
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(refresh: true);

        return self::describe();
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        return [
            'enforce_month_end_run_schedule' => (bool) ($settings['enforce_month_end_run_schedule'] ?? true),
        ];
    }
}
