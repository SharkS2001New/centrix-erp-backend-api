<?php

namespace App\Services\Payroll;

use App\Models\Organization;

/**
 * Platform-wide Kenya statutory payroll rates (PAYE bands, reliefs, NSSF, SHIF, AHL).
 * Defaults come from config/kenya_payroll.php; platform admins may override when laws change.
 */
class KenyaPayrollSettingsResolver
{
    public const MODULE_KEY = 'platform_kenya_payroll';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $cfg = config('kenya_payroll', []);

        return self::normalize(is_array($cfg) ? $cfg : []);
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

    /** Effective rates used by the calculator (defaults + platform overrides). */
    /** @return array<string, mixed> */
    public static function resolve(): array
    {
        return self::forPlatform();
    }

    /** @return array<string, mixed> */
    public static function forPlatform(): array
    {
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::MODULE_KEY] ?? null)
            ? $org->module_settings[self::MODULE_KEY]
            : [];

        return self::normalize(array_replace_recursive(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $settings = self::forPlatform();
        $defaults = self::defaults();
        $paye = $settings['paye'];
        $firstBand = $paye['bands'][0] ?? ['up_to' => 24000, 'rate' => 0.10];
        $firstRate = max(0.0001, (float) ($firstBand['rate'] ?? 0.10));
        $approxTaxFreeTaxable = round((float) $paye['personal_relief_monthly'] / $firstRate, 2);

        return [
            'scope' => 'platform',
            'settings' => $settings,
            'defaults' => $defaults,
            'hints' => [
                'personal_relief' => 'Monthly personal tax relief subtracted from computed PAYE (KRA).',
                'first_band' => 'First PAYE band upper limit on taxable income (typically KES 24,000 at 10%).',
                'approx_tax_free_taxable' => 'Approx. taxable income where personal relief fully offsets tax on the first band (relief ÷ first-band rate).',
                'override' => 'Stored on the PLATFORM organization. Empty overrides keep file defaults from config/kenya_payroll.php.',
            ],
            'effective' => [
                'personal_relief_monthly' => $paye['personal_relief_monthly'],
                'first_band_up_to' => $firstBand['up_to'] ?? null,
                'approx_tax_free_taxable_income' => $approxTaxFreeTaxable,
                'effective_label' => $settings['effective_label'],
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
        $merged = array_replace_recursive($current, $incoming);
        $moduleSettings[self::MODULE_KEY] = self::normalize($merged);
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(refresh: true);

        return self::describe();
    }

    /** Reset platform overrides back to file defaults. */
    /** @return array<string, mixed> */
    public static function resetToDefaults(): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(503, 'Platform organization is not configured.');
        }

        $moduleSettings = $org->module_settings ?? [];
        unset($moduleSettings[self::MODULE_KEY]);
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(refresh: true);

        return self::describe();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings): array
    {
        $file = config('kenya_payroll', []);
        $payeIn = is_array($settings['paye'] ?? null) ? $settings['paye'] : [];
        $nssfIn = is_array($settings['nssf'] ?? null) ? $settings['nssf'] : [];
        $shifIn = is_array($settings['shif'] ?? null) ? $settings['shif'] : [];
        $hlIn = is_array($settings['housing_levy'] ?? null) ? $settings['housing_levy'] : [];

        $bands = self::normalizeBands($payeIn['bands'] ?? ($file['paye']['bands'] ?? []));

        return [
            'effective_label' => trim((string) ($settings['effective_label'] ?? $file['effective_label'] ?? '2026')),
            'paye' => [
                'personal_relief_monthly' => round(max(0, (float) ($payeIn['personal_relief_monthly'] ?? $file['paye']['personal_relief_monthly'] ?? 2400)), 2),
                'insurance_relief_rate' => max(0, min(1, (float) ($payeIn['insurance_relief_rate'] ?? $file['paye']['insurance_relief_rate'] ?? 0.15))),
                'insurance_relief_cap_monthly' => round(max(0, (float) ($payeIn['insurance_relief_cap_monthly'] ?? $file['paye']['insurance_relief_cap_monthly'] ?? 5000)), 2),
                'bands' => $bands,
            ],
            'nssf' => [
                'rate' => max(0, min(1, (float) ($nssfIn['rate'] ?? $file['nssf']['rate'] ?? 0.06))),
                'tier1_upper' => round(max(0, (float) ($nssfIn['tier1_upper'] ?? $file['nssf']['tier1_upper'] ?? 9000)), 2),
                'tier2_upper' => round(max(0, (float) ($nssfIn['tier2_upper'] ?? $file['nssf']['tier2_upper'] ?? 108000)), 2),
            ],
            'shif' => [
                'rate' => max(0, min(1, (float) ($shifIn['rate'] ?? $file['shif']['rate'] ?? 0.0275))),
                'minimum_monthly' => round(max(0, (float) ($shifIn['minimum_monthly'] ?? $file['shif']['minimum_monthly'] ?? 300)), 2),
            ],
            'housing_levy' => [
                'employee_rate' => max(0, min(1, (float) ($hlIn['employee_rate'] ?? $file['housing_levy']['employee_rate'] ?? 0.015))),
                'employer_rate' => max(0, min(1, (float) ($hlIn['employer_rate'] ?? $file['housing_levy']['employer_rate'] ?? 0.015))),
            ],
        ];
    }

    /**
     * @param  mixed  $bands
     * @return list<array{up_to: float|null, rate: float}>
     */
    protected static function normalizeBands(mixed $bands): array
    {
        $fileBands = config('kenya_payroll.paye.bands', []);
        if (! is_array($bands) || $bands === []) {
            $bands = is_array($fileBands) ? $fileBands : [];
        }

        $out = [];
        foreach ($bands as $band) {
            if (! is_array($band)) {
                continue;
            }
            $upTo = $band['up_to'] ?? null;
            if ($upTo === '' || $upTo === 'null' || $upTo === 'above') {
                $upTo = null;
            } elseif ($upTo !== null) {
                $upTo = round(max(0, (float) $upTo), 2);
            }
            $rate = max(0, min(1, (float) ($band['rate'] ?? 0)));
            $out[] = ['up_to' => $upTo, 'rate' => $rate];
        }

        if ($out === []) {
            return [
                ['up_to' => 24000.0, 'rate' => 0.10],
                ['up_to' => 32333.0, 'rate' => 0.25],
                ['up_to' => 500000.0, 'rate' => 0.30],
                ['up_to' => 800000.0, 'rate' => 0.325],
                ['up_to' => null, 'rate' => 0.35],
            ];
        }

        // Ensure the last band is open-ended.
        $last = count($out) - 1;
        $out[$last]['up_to'] = null;

        return $out;
    }
}
