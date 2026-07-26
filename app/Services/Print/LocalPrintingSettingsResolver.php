<?php

namespace App\Services\Print;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class LocalPrintingSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.local_printing', [
            'provider' => 'browser',
            'printer_name' => '',
            'copies' => 1,
            'fallback_to_browser' => true,
            'require_qz' => false,
            'use_signing' => false,
        ]);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $stored = is_array($organization->module_settings['local_printing'] ?? null)
            ? $organization->module_settings['local_printing']
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        return self::normalize(array_merge(
            self::defaults(),
            $gate->moduleSettings('local_printing'),
        ));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);

        $provider = strtolower(trim((string) ($out['provider'] ?? 'browser')));
        if ($provider === 'qz' || $provider === 'qz-tray' || $provider === 'qz_tray') {
            $out['provider'] = 'qz';
        } elseif ($provider === 'agent' || $provider === 'print-agent' || $provider === 'print_agent') {
            $out['provider'] = 'agent';
        } else {
            $out['provider'] = 'browser';
        }

        $out['printer_name'] = trim((string) ($out['printer_name'] ?? ''));
        $out['copies'] = max(1, min(10, (int) ($out['copies'] ?? 1)));
        $out['fallback_to_browser'] = true;
        $out['require_qz'] = false;
        $out['use_signing'] = filter_var($out['use_signing'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $out;
    }
}
