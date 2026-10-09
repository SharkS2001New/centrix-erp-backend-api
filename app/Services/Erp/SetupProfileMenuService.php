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
        return array_values(array_unique(array_merge($this->builtinKeys(), $this->customKeys())));
    }

    /** @return list<string> */
    public function builtinKeys(): array
    {
        return array_values(array_filter(
            array_keys(config('erp.profiles', [])),
            fn (string $key) => $key !== 'platform',
        ));
    }

    /** @return list<string> */
    public function customKeys(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return PlatformSetupProfile::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (PlatformSetupProfile $row) => ($row->menu_groups['custom'] ?? false) === true)
            ->pluck('profile_key')
            ->map(fn ($key) => (string) $key)
            ->values()
            ->all();
    }

    public function isCustom(string $profileKey): bool
    {
        return in_array($profileKey, $this->customKeys(), true);
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
            $industry = is_string($profile['industry'] ?? null) && $profile['industry'] !== ''
                ? $profile['industry']
                : $this->storedIndustry($profileKey);

            return $this->applicationIdsForIndustry($industry);
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
     * @return list<string>
     */
    public function applicationIdsForIndustry(string $industry): array
    {
        return $industry === 'hospitality'
            ? ['hotel_bar_pos', 'hospitality_backoffice', 'accounting', 'hr', 'admin']
            : ['pos', 'backoffice', 'distribution', 'accounting', 'hr', 'admin'];
    }

    /**
     * @return list<array{key: string, label: string, industry: string|null, custom: bool, application_ids: list<string>, applications: array<string, bool>, menu_groups: array<string, mixed>}>
     */
    public function all(): array
    {
        $profiles = config('erp.profiles', []);
        $rows = [];
        foreach ($this->profileKeys() as $key) {
            $profile = $profiles[$key] ?? [];
            $rows[] = $this->present($key, (string) ($profile['label'] ?? $key), $profile['industry'] ?? null, false);
        }

        foreach ($this->customKeys() as $key) {
            $stored = $this->storedDocument($key) ?? [];
            $rows[] = $this->present(
                $key,
                (string) ($stored['label'] ?? $key),
                is_string($stored['industry'] ?? null) ? $stored['industry'] : 'commerce',
                true,
            );
        }

        return $rows;
    }

    /**
     * @return array{key: string, label: string, industry: string|null, custom: bool, application_ids: list<string>, applications: array<string, bool>, menu_groups: array<string, mixed>}
     */
    public function create(string $name, string $industry): array
    {
        if (! $this->tableReady()) {
            throw new \RuntimeException('Setup types are not ready yet. Run the latest database migration.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Give this setup type a name.');
        }
        if (mb_strlen($name) > 80) {
            throw new \InvalidArgumentException('The name must be 80 characters or fewer.');
        }
        if (! in_array($industry, ['commerce', 'hospitality'], true)) {
            throw new \InvalidArgumentException('Choose Retail & Distribution or Hotel & Hospitality.');
        }
        if ($this->labelTaken($name)) {
            throw new \InvalidArgumentException('A setup type with this name already exists.');
        }

        $key = $this->uniqueCustomKey($name);
        $applications = array_fill_keys($this->applicationIdsForIndustry($industry), false);
        $menu = $this->applyApplicationGate($this->normalize(null), $applications);

        PlatformSetupProfile::query()->create([
            'profile_key' => $key,
            'menu_groups' => array_merge($menu, [
                'applications' => $applications,
                'label' => $name,
                'industry' => $industry,
                'custom' => true,
            ]),
        ]);

        return $this->present($key, $name, $industry, true);
    }

    public function delete(string $profileKey): void
    {
        if (! $this->isCustom($profileKey)) {
            throw new \InvalidArgumentException('Built-in setup types stay in the list.');
        }

        if (\App\Models\Organization::query()->where('deployment_profile', $profileKey)->exists()) {
            throw new \InvalidArgumentException('An organisation still uses this setup type. Choose another setup on that organisation first.');
        }

        PlatformSetupProfile::query()->where('profile_key', $profileKey)->delete();
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

        $menu = $this->applyApplicationGate($menu, $applications);
        $custom = ($existing['custom'] ?? false) === true || $this->isCustom($profileKey);
        $label = $custom
            ? $this->resolveCustomLabel($profileKey, $payload, $existing)
            : (string) (config('erp.profiles.'.$profileKey.'.label') ?? $profileKey);
        $industry = $custom
            ? $this->storedIndustry($profileKey)
            : (config('erp.profiles.'.$profileKey.'.industry') ?? null);

        $document = array_merge($menu, ['applications' => $applications]);
        if ($custom) {
            $document['custom'] = true;
            $document['label'] = $label;
            $document['industry'] = $industry;
        }

        if ($this->tableReady()) {
            PlatformSetupProfile::query()->updateOrCreate(
                ['profile_key' => $profileKey],
                ['menu_groups' => $document],
            );
        }

        return [
            'menu_groups' => $menu,
            'applications' => $applications,
            'label' => $label,
            'industry' => $industry,
            'custom' => $custom,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $existing
     */
    protected function resolveCustomLabel(string $profileKey, array $payload, ?array $existing): string
    {
        $current = trim((string) ($existing['label'] ?? ''));
        if (! array_key_exists('label', $payload)) {
            return $current !== '' ? $current : $profileKey;
        }

        $label = trim((string) $payload['label']);
        if ($label === '') {
            throw new \InvalidArgumentException('Give this setup type a name.');
        }
        if (mb_strlen($label) > 80) {
            throw new \InvalidArgumentException('The name must be 80 characters or fewer.');
        }
        if ($this->labelTaken($label, $profileKey)) {
            throw new \InvalidArgumentException('A setup type with this name already exists.');
        }

        return $label;
    }

    protected function labelTaken(string $name, ?string $exceptKey = null): bool
    {
        $needle = mb_strtolower(trim($name));
        foreach ($this->all() as $row) {
            if ($exceptKey !== null && $row['key'] === $exceptKey) {
                continue;
            }
            if (mb_strtolower(trim((string) $row['label'])) === $needle) {
                return true;
            }
        }

        return false;
    }

    protected function uniqueCustomKey(string $name): string
    {
        $slug = \Illuminate\Support\Str::slug($name);
        if ($slug === '') {
            $slug = 'setup';
        }
        $base = 'custom_'.substr($slug, 0, 48);
        $key = substr($base, 0, 60);
        $n = 2;
        $taken = array_flip($this->profileKeys());
        $n = 2;
        while (isset($taken[$key]) || ($this->tableReady() && PlatformSetupProfile::query()->where('profile_key', $key)->exists())) {
            if ($n > 50) {
                throw new \InvalidArgumentException('Could not create a unique setup type.');
            }
            $suffix = '_'.$n;
            $key = substr($base, 0, 60 - strlen($suffix)).$suffix;
            $n++;
        }

        return $key;
    }

    protected function storedIndustry(string $profileKey): string
    {
        $stored = $this->storedDocument($profileKey);
        $industry = is_array($stored) ? ($stored['industry'] ?? null) : null;

        return $industry === 'hospitality' ? 'hospitality' : 'commerce';
    }

    /**
     * @return array{key: string, label: string, industry: string|null, custom: bool, application_ids: list<string>, applications: array<string, bool>, menu_groups: array<string, mixed>}
     */
    protected function present(string $key, string $label, ?string $industry, bool $custom): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'industry' => $industry,
            'custom' => $custom,
            'application_ids' => $this->applicationIdsForProfile($key),
            'applications' => $this->applicationsForProfile($key),
            'menu_groups' => $this->forProfile($key),
        ];
    }

    /**
     * Menu groups stay off unless that application is included in the setup type.
     *
     * @param  array{backoffice: array<string, bool>, hr: array<string, bool>, accounting: array<string, bool>}  $menu
     * @param  array<string, bool>  $applications
     * @return array{backoffice: array<string, bool>, hr: array<string, bool>, accounting: array<string, bool>}
     */
    protected function applyApplicationGate(array $menu, array $applications): array
    {
        $backofficeOn = ($applications['backoffice'] ?? false) === true
            || ($applications['hospitality_backoffice'] ?? false) === true;
        if (! $backofficeOn) {
            $menu['backoffice'] = array_fill_keys(BackofficeSections::SECTION_KEYS, false);
        }
        if (($applications['hr'] ?? false) !== true) {
            $menu['hr'] = array_fill_keys(self::HR_KEYS, false);
        }
        if (($applications['accounting'] ?? false) !== true) {
            $menu['accounting'] = array_fill_keys(self::ACCOUNTING_KEYS, false);
        }

        return $menu;
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
