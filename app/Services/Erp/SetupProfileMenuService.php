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
        return $this->normalize($this->storedDocument($profileKey));
    }

    /**
     * Applications included in this setup type. External POS is `pos`.
     * Unsaved setups use the profile's built-in modules.
     *
     * @return array<string, bool>
     */
    public function applicationsForProfile(string $profileKey): array
    {
        $stored = $this->storedDocument($profileKey);
        $raw = is_array($stored['applications'] ?? null) ? $stored['applications'] : null;

        return $this->normalizeApplications($profileKey, $raw);
    }

    /**
     * @return list<string>
     */
    public function applicationIdsForProfile(string $profileKey): array
    {
        $profile = config('erp.profiles.'.$profileKey, []);
        $ids = $profile['application_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            $industry = $profile['industry'] ?? 'commerce';

            return $industry === 'hospitality'
                ? ['hotel_bar_pos', 'hospitality_backoffice', 'accounting', 'hr', 'admin']
                : ['pos', 'backoffice', 'distribution', 'accounting', 'hr', 'admin'];
        }

        return array_values(array_map('strval', $ids));
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, bool>
     */
    public function normalizeApplications(string $profileKey, ?array $raw): array
    {
        $defaults = $this->defaultApplications($profileKey);
        if (! is_array($raw)) {
            return $defaults;
        }

        foreach (array_keys($defaults) as $id) {
            if (array_key_exists($id, $raw)) {
                $defaults[$id] = filter_var($raw[$id], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (($defaults['pos'] ?? false) === true) {
            $defaults['backoffice'] = true;
        }
        if (($defaults['hotel_bar_pos'] ?? false) === true) {
            $defaults['hospitality_backoffice'] = true;
        }

        return $defaults;
    }

    /**
     * @return array<string, bool>
     */
    public function defaultApplications(string $profileKey): array
    {
        $profile = config('erp.profiles.'.$profileKey, []);
        $fromModules = app(ApplicationProvisioner::class)->applicationsFromProfileModules(
            is_array($profile['modules'] ?? null) ? $profile['modules'] : [],
        );
        $out = [];
        foreach ($this->applicationIdsForProfile($profileKey) as $id) {
            $out[$id] = (bool) ($fromModules[$id] ?? false);
        }

        return $out;
    }

    /**
     * @return list<array{key: string, label: string, industry: string|null, application_ids: list<string>, applications: array<string, bool>, menu_groups: array<string, mixed>}>
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
                'application_ids' => $this->applicationIdsForProfile($key),
                'applications' => $this->applicationsForProfile($key),
                'menu_groups' => $this->forProfile($key),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{menu_groups: array<string, mixed>, applications: array<string, bool>}
     */
    public function save(string $profileKey, array $payload): array
    {
        if (! in_array($profileKey, $this->profileKeys(), true)) {
            throw new \InvalidArgumentException('Unknown setup type.');
        }

        $existing = $this->storedDocument($profileKey);
        $menu = $this->normalize($payload);
        $applications = array_key_exists('applications', $payload)
            ? $this->normalizeApplications(
                $profileKey,
                is_array($payload['applications']) ? $payload['applications'] : [],
            )
            : $this->normalizeApplications(
                $profileKey,
                is_array($existing['applications'] ?? null) ? $existing['applications'] : null,
            );

        if ($this->tableReady()) {
            PlatformSetupProfile::query()->updateOrCreate(
                ['profile_key' => $profileKey],
                ['menu_groups' => array_merge($menu, ['applications' => $applications])],
            );
        }

        return [
            'menu_groups' => $menu,
            'applications' => $applications,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function storedDocument(string $profileKey): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = PlatformSetupProfile::query()->where('profile_key', $profileKey)->first();

        return is_array($row?->menu_groups) ? $row->menu_groups : null;
    }

    protected function tableReady(): bool
    {
        return Schema::hasTable('platform_setup_profiles');
    }
}
