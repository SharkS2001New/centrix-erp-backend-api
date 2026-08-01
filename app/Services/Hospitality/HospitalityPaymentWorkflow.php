<?php

namespace App\Services\Hospitality;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

/**
 * Hospitality check payment workflow — only three statuses after drafting:
 * unpaid | partially_paid | paid (plus open draft / void).
 * Platform admin can enable or disable each status for an organization.
 */
class HospitalityPaymentWorkflow
{
    public const KEYS = ['unpaid', 'partially_paid', 'paid'];

    /** @var array<string, bool> */
    public const DEFAULTS = [
        'unpaid' => true,
        'partially_paid' => true,
        'paid' => true,
    ];

    /** @var array<string, array{label: string, description: string}> */
    public const CATALOG = [
        'unpaid' => [
            'label' => 'Unpaid',
            'description' => 'Save order / print receipt — payment collected later by cashier.',
        ],
        'partially_paid' => [
            'label' => 'Partially paid',
            'description' => 'Customer paid some of the bill; balance still due.',
        ],
        'paid' => [
            'label' => 'Paid',
            'description' => 'Fully settled — buy and pay now, or balance cleared later.',
        ],
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

        // Paid is required for collect-payment / settle to complete.
        if (! $out['paid']) {
            $out['paid'] = true;
        }

        return $out;
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
        $raw = is_array($hospitality['payment_workflow'] ?? null) ? $hospitality['payment_workflow'] : [];

        return self::normalize($raw);
    }

    public static function enabled(?Organization $organization, string $status): bool
    {
        $workflow = self::forOrganization($organization);

        return (bool) ($workflow[$status] ?? false);
    }

    /**
     * @return array{payment_workflow: array<string, bool>, catalog: array<string, array{label: string, description: string}>}
     */
    public static function presentForOrganization(?Organization $organization): array
    {
        return [
            'payment_workflow' => self::forOrganization($organization),
            'payment_workflow_catalog' => self::CATALOG,
        ];
    }
}
