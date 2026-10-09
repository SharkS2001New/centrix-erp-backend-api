<?php

namespace App\Services\Erp;

use App\Models\PlatformSetupProfile;
use Illuminate\Support\Facades\Schema;

/**
 * Menu groups saved for each setup type (Small shop, Wholesale, and the rest).
 * Choosing that setup on an organisation copies these groups onto it.
 */
class SetupProfileMenuService
{
    /** @var list<string> */
    public const HR_KEYS = [
        'hr_people',
        'hr_time_attendance',
        'hr_payroll',
        'hr_performance',
        'reports',
    ];

    /** @var list<string> */
    public const ACCOUNTING_KEYS = [
        'accounting',
        'expenses',
        'reports',
    ];

    /**
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public static function defaultGroup(array $keys): array
    {
        return array_fill_keys($keys, true);
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>|null  $raw
     * @return array<string, bool>
     */
    public static function normalizeGroup(array $keys, ?array $raw): array
    {
        $out = self::defaultGroup($keys);
        if (! is_array($raw)) {
            return $out;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $sales
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public static function storedGroup(array $sales, string $field, array $keys): array
    {
        $raw = is_array($sales[$field] ?? null) ? $sales[$field] : null;

        return self::normalizeGroup($keys, $raw);
    }

    /**
     * @return array{backoffice: array<string, bool>, hr: array<string, bool>, accounting: array<string, bool>}
     */
    public function defaults(): array
    {
        return [
            'backoffice' => BackofficeSections::DEFAULTS,
            'hr' => self::defaultGroup(self::HR_KEYS),
            'accounting' => self::defaultGroup(self::ACCOUNTING_KEYS),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{backoffice: array<string, bool>, hr: array<string, bool>, accounting: array<string, bool>}
     */
    public function normalize(?array $raw): array
    {
        $defaults = $this->defaults();

        return [
            'backoffice' => BackofficeSections::normalize(is_array($raw['backoffice'] ?? null) ? $raw['backoffice'] : $defaults['backoffice']),
            'hr' => self::normalizeGroup(self::HR_KEYS, is_array($raw['hr'] ?? null) ? $raw['hr'] : null),
            'accounting' => self::normalizeGroup(self::ACCOUNTING_KEYS, is_array($raw['accounting'] ?? null) ? $raw['accounting'] : null),
        ];
    }

    /** @return list<string> */
    public function profileKeys(): array
    {
        return array_values(array_filter(
            array_keys(config('erp.profiles', [])),
            fn (string $key) => $key !== 'platform',
        ));
    }

    /**
     * @return array{backoffice: array<string, bool>, hr: array<string, bool>, accounting: array<string, bool>}
     */
    public function forProfile(string $profileKey): array
    {
        if (! $this->tableReady()) {
            return $this->defaults();
        }

        $row = PlatformSetupProfile::query()->where('profile_key', $profileKey)->first();
        $stored = is_array($row?->menu_groups) ? $row->menu_groups : null;

        return $this->normalize($stored);
    }

    /**
     * @return list<array{key: string, label: string, industry: string|null, menu_groups: array<string, mixed>}>
     */
    public function all(): array
    {
        $profiles = config('erp.profiles', []);
        $rows = [];
        foreach ($this->profileKeys() as $key) {
            $profile = $profiles[$key] ?? [];
            $rows[] = [
                'key' => $key,
                'label' => (string) ($profile['label'] ?? $key),
                'industry' => $profile['industry'] ?? null,
                'menu_groups' => $this->forProfile($key),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $menuGroups
     * @return array{backoffice: array<string, bool>, hr: array<string, bool>, accounting: array<string, bool>}
     */
    public function save(string $profileKey, array $menuGroups): array
    {
        if (! in_array($profileKey, $this->profileKeys(), true)) {
            throw new \InvalidArgumentException('Unknown setup type.');
        }

        $normalized = $this->normalize($menuGroups);
        if ($this->tableReady()) {
            PlatformSetupProfile::query()->updateOrCreate(
                ['profile_key' => $profileKey],
                ['menu_groups' => $normalized],
            );
        }

        return $normalized;
    }

    protected function tableReady(): bool
    {
        return Schema::hasTable('platform_setup_profiles');
    }
}
