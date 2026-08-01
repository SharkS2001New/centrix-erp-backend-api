<?php

namespace App\Services\Hospitality;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class HospitalityPosSettings
{
    public const GRID_COLUMNS_DEFAULT = 4;

    public const GRID_COLUMNS_ALLOWED = [4, 5];

    /**
     * @param  array<string, mixed>|null  $hospitalitySettings
     */
    public static function normalizeGridColumns(mixed $value, ?array $hospitalitySettings = null): int
    {
        $raw = $value;
        if ($raw === null && is_array($hospitalitySettings)) {
            $raw = $hospitalitySettings['hotel_pos_grid_columns'] ?? null;
        }
        $n = (int) $raw;
        if (! in_array($n, self::GRID_COLUMNS_ALLOWED, true)) {
            return self::GRID_COLUMNS_DEFAULT;
        }

        return $n;
    }

    public static function gridColumnsForOrganization(?Organization $organization): int
    {
        if (! $organization) {
            return self::GRID_COLUMNS_DEFAULT;
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $settings = $gate->moduleSettings('hospitality');

        return self::normalizeGridColumns($settings['hotel_pos_grid_columns'] ?? null);
    }
}
