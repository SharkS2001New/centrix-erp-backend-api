<?php

namespace App\Services\Erp;

/**
 * Platform-controlled advanced data import destinations (one per import UI / API).
 */
class AdvancedDataImportPageRegistry
{
    /** @return array<string, array{label: string, default: bool}> */
    public static function definitions(): array
    {
        return config('erp.advanced_data_import_pages', []);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /** @return array<string, bool> */
    public static function defaultEnabledMap(): array
    {
        $map = [];
        foreach (self::definitions() as $key => $meta) {
            $map[$key] = (bool) ($meta['default'] ?? false);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     * @return array<string, bool>
     */
    public static function resolveEnabledMap(?array $overrides, bool $masterEnabled): array
    {
        $defaults = self::defaultEnabledMap();
        if (! $masterEnabled) {
            return array_map(static fn () => false, $defaults);
        }

        $overrides = is_array($overrides) ? $overrides : [];
        $resolved = [];
        foreach ($defaults as $key => $default) {
            $resolved[$key] = array_key_exists($key, $overrides)
                ? (bool) $overrides[$key]
                : $default;
        }

        return $resolved;
    }

    /**
     * @param  mixed  $input
     * @return array<string, bool>
     */
    public static function normalizeOverrides(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $normalized = [];
        foreach (self::keys() as $key) {
            if (array_key_exists($key, $input)) {
                $normalized[$key] = (bool) $input[$key];
            }
        }

        return $normalized;
    }
}
