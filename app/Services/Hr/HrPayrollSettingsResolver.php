<?php

namespace App\Services\Hr;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class HrPayrollSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.hr_payroll', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $stored = is_array($organization->module_settings['hr_payroll'] ?? null)
            ? $organization->module_settings['hr_payroll']
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function forOrganizationId(int $organizationId): array
    {
        $organization = Organization::find($organizationId);

        return $organization
            ? self::forOrganization($organization)
            : self::normalize(self::defaults());
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        return self::normalize(array_merge(
            self::defaults(),
            $gate->moduleSettings('hr_payroll'),
        ));
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);

        $out['grace_days_after_month_end'] = max(1, min(31, (int) ($out['grace_days_after_month_end'] ?? 7)));
        $out['payroll_run_delete_lock_minutes'] = max(1, min(1440, (int) ($out['payroll_run_delete_lock_minutes'] ?? 20)));
        $out['standard_work_hours_per_day'] = max(1, min(24, (float) ($out['standard_work_hours_per_day'] ?? 8)));
        $out['overtime_rate_multiplier'] = max(1, min(5, (float) ($out['overtime_rate_multiplier'] ?? 1.5)));
        $out['default_probation_months'] = max(0, min(24, (int) ($out['default_probation_months'] ?? 3)));

        foreach ([
            'auto_calculate_statutory',
            'close_cycle_on_process',
            'include_overtime_in_payroll',
            'include_other_deductions_in_payroll',
            'require_payroll_approval',
            'require_attendance_for_payroll',
            'enable_cash_advance_deductions',
            'deduct_cash_advances_on_payroll',
        ] as $flag) {
            $out[$flag] = (bool) ($out[$flag] ?? false);
        }

        $out['pay_frequency'] = in_array($out['pay_frequency'] ?? '', ['monthly'], true)
            ? $out['pay_frequency']
            : 'monthly';

        return $out;
    }
}
