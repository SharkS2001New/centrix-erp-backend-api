<?php

namespace App\Services\Hospitality;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

/**
 * Platform-controlled hospitality services.
 * Default org surface: Main outlet (always) + Rooms.
 * Everything else is opted in by platform admin.
 */
class HospitalityServices
{
    public const SERVICE_KEYS = [
        'rooms',
        'reservations',
        'front_desk',
        'folios',
        'housekeeping',
        'night_audit',
        'extra_outlets',
        'floor_tables',
        'table_pos',
        'room_charge',
    ];

    /** @var array<string, bool> */
    public const DEFAULTS = [
        'rooms' => true,
        'reservations' => false,
        'front_desk' => false,
        'folios' => false,
        'housekeeping' => false,
        'night_audit' => false,
        'extra_outlets' => false,
        'floor_tables' => false,
        'table_pos' => false,
        'room_charge' => false,
    ];

    /** @var array<string, array{label: string, description: string}> */
    public const CATALOG = [
        'rooms' => [
            'label' => 'Rooms',
            'description' => 'Room types and room inventory. Optional — Hotel & Bar POS sells without rooms.',
        ],
        'reservations' => [
            'label' => 'Reservations',
            'description' => 'Booking calendar and reservation management.',
        ],
        'front_desk' => [
            'label' => 'Front desk',
            'description' => 'Check-in / check-out and room assignment.',
        ],
        'folios' => [
            'label' => 'Guest folios (pay later)',
            'description' => 'Running guest bill for room + extras. Leave off for pay-at-check-in hotels — front desk just assigns the room.',
        ],
        'housekeeping' => [
            'label' => 'Housekeeping',
            'description' => 'Room status board (clean / dirty / OOO).',
        ],
        'night_audit' => [
            'label' => 'Night audit',
            'description' => 'End-of-day close and room charge posting to open folios. Requires Guest folios.',
        ],
        'extra_outlets' => [
            'label' => 'Extra outlets',
            'description' => 'Manage more outlets beyond the default Main outlet.',
        ],
        'floor_tables' => [
            'label' => 'Floor tables',
            'description' => 'Restaurant / bar table map for dine-in checks.',
        ],
        'table_pos' => [
            'label' => 'Table POS mode',
            'description' => 'Cashier must select a floor table before saving or collecting payment.',
        ],
        'room_charge' => [
            'label' => 'Room charge from POS',
            'description' => 'Post bar/restaurant checks to an open guest folio. Requires Guest folios. Leave off if F&B is pay-at-till only.',
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
        foreach (self::SERVICE_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Room charge and night audit need open guest folios.
        if ($out['room_charge'] || $out['night_audit']) {
            $out['folios'] = true;
        }
        if (! $out['folios']) {
            $out['room_charge'] = false;
            $out['night_audit'] = false;
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
        $services = is_array($hospitality['services'] ?? null) ? $hospitality['services'] : [];

        return self::normalize($services);
    }

    public static function enabled(?Organization $organization, string $service): bool
    {
        $services = self::forOrganization($organization);

        return (bool) ($services[$service] ?? false);
    }

    /**
     * @return array{services: array<string, bool>, catalog: array<string, array{label: string, description: string}>, main_outlet: true}
     */
    public static function presentForOrganization(?Organization $organization): array
    {
        return [
            'services' => self::forOrganization($organization),
            'catalog' => self::CATALOG,
            'main_outlet' => true,
        ];
    }
}
