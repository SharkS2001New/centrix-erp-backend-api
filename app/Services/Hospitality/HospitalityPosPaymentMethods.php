<?php

namespace App\Services\Hospitality;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

/**
 * Which tenders appear on Hotel & Bar POS Collect payment.
 * Platform admin only — tenant Admin → Payment methods still holds codes/labels.
 */
class HospitalityPosPaymentMethods
{
    public const KEYS = ['cash', 'mpesa', 'equity', 'kcb', 'other_bank', 'cheque', 'extra'];

    /**
     * New hotel tenants: cash + M-Pesa. Banks and extras stay off until enabled.
     *
     * @var array<string, bool>
     */
    public const DEFAULTS = [
        'cash' => true,
        'mpesa' => true,
        'equity' => false,
        'kcb' => false,
        'other_bank' => false,
        'cheque' => false,
        'extra' => false,
    ];

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, bool>
     */
    public static function normalize(?array $raw): array
    {
        $out = self::DEFAULTS;
        if (! is_array($raw)) {
            return $out;
        }
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /**
     * When the org has never saved Hotel POS tenders, follow Sales payment fields
     * so existing hotels keep Equity / KCB / Cheque until platform admin changes them.
     *
     * @param  array<string, mixed>  $sales
     * @return array<string, bool>
     */
    public static function fromSalesSettings(array $sales): array
    {
        return [
            'cash' => true,
            'mpesa' => ($sales['enable_mpesa_amount'] ?? true) !== false,
            'equity' => (bool) ($sales['enable_equity_bank'] ?? true),
            'kcb' => (bool) ($sales['enable_kcb_bank'] ?? true),
            'other_bank' => (bool) ($sales['enable_other_bank'] ?? false),
            'cheque' => ($sales['enable_cheque'] ?? true) !== false,
            'extra' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function forOrganization(?Organization $organization): array
    {
        if (! $organization) {
            return self::DEFAULTS;
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $hospitality = $gate->moduleSettings('hospitality');
        $raw = $hospitality['payment_methods'] ?? null;
        if (is_array($raw) && $raw !== []) {
            return self::normalize($raw);
        }

        return self::fromSalesSettings($gate->moduleSettings('sales'));
    }
}
