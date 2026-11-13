<?php

namespace App\Services\Erp;

use App\Models\PlatformSetupProfile;
use Illuminate\Support\Facades\Schema;

/**
 * Menu groups and platform defaults saved for each setup type.
 * Choosing that setup on an organisation copies these defaults onto it.
 */
class SetupProfileMenuService
{
    /** @var list<string> */
    private const SALES_PLATFORM_SETTING_KEYS = [
        'show_checkout_on_create_order',
        'backoffice_product_search_mode',
        'enable_mobile_orders',
        'mobile_enable_field_attendance',
        'mobile_enable_driver_app',
        'mobile_enable_driver_attendance',
        'enable_mobile_orders_returns_card',
        'enable_mobile_orders_payments_card',
        'enable_mobile_orders_expenses_card',
        'enable_centrix_as_mobile',
        'as_mobile_show_images',
        'require_product_images',
        'as_mobile_post_login_screen',
        'append_same_day_customer_orders',
        'enable_mpesa_stk',
        'enable_equity_bank',
        'enable_kra_integration',
        'enable_ai',
        'enable_ai_chat',
        'enable_ai_talk',
        'use_platform_gemini',
        'enable_whatsapp_orders',
        'enable_investors',
        'enable_advanced_data_import',
        'advanced_data_import_pages',
        'hospitality_services',
        'hospitality_payment_workflow',
        'hotel_pos_payment_methods',
        'hotel_pos_collect_payment',
        'stock_deduct_on',
        'reserve_stock_on_cart',
        'cart_reservation_ttl_minutes',
        'enable_receive_batch_tracking',
        'orders_list_default_days',
        'shop_debtors_default_days',
        'reports_default_date_range_days',
        'orders_list_search_days',
        'orders_list_sort',
        'orders_list_visible_columns',
        'orders_list_visible_columns_by_queue',
        'order_expiry_enabled',
        'order_expiry_days',
        'order_expiry_before_status',
        'order_cancellation_enabled',
        'order_workflow',
        'edit_order_statuses',
        'print_invoice_statuses',
        'collect_payment_statuses',
        'cancel_order_statuses',
        'convert_to_paid_statuses',
        'convert_to_unpaid_statuses',
        'customer_return_statuses',
    ];

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

    /** @return array<string, mixed> */
    public function salesPlatformSettingsForProfile(string $profileKey): array
    {
        $stored = $this->storedDocument($profileKey);
        $settings = is_array($stored['sales_platform'] ?? null) ? $stored['sales_platform'] : [];

        return array_intersect_key($settings, array_flip(self::SALES_PLATFORM_SETTING_KEYS));
    }

    /** @return array<string, mixed> */
    public function payrollPlatformSettingsForProfile(string $profileKey): array
    {
        $stored = $this->storedDocument($profileKey);
        $settings = is_array($stored['payroll_platform'] ?? null) ? $stored['payroll_platform'] : [];

        return array_intersect_key($settings, array_flip([
            'payroll_month_days_basis',
            'shif_minimum_monthly',
        ]));
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
        return match ($industry) {
            'hospitality' => ['hotel_bar_pos', 'hospitality_backoffice', 'accounting', 'hr', 'admin'],
            'spa' => ['spa'],
            'driving' => ['driving', 'admin'],
            default => ['pos', 'backoffice', 'distribution', 'accounting', 'hr', 'admin'],
        };
    }

    /**
     * Business types (marketing / trial signup) this setup may appear under.
     * Empty list = any business type in the industry (Instant trial only).
     * Assigned types are exclusive: each value may belong to at most one setup type.
     *
     * @return list<string>
     */
    public function businessTypesForProfile(string $profileKey): array
    {
        $stored = $this->storedDocument($profileKey);
        if (is_array($stored) && array_key_exists('business_types', $stored)) {
            return $this->normalizeBusinessTypes($stored['business_types']);
        }

        $fromConfig = config('erp.profiles.'.$profileKey.'.business_types');

        return $this->normalizeBusinessTypes(is_array($fromConfig) ? $fromConfig : null);
    }

    /**
     * Map of business type value => owning setup {key, label}, excluding one profile.
     *
     * @return array<string, array{key: string, label: string}>
     */
    public function businessTypeOwners(?string $exceptKey = null): array
    {
        $owners = [];
        foreach ($this->all() as $row) {
            if ($exceptKey !== null && $row['key'] === $exceptKey) {
                continue;
            }
            foreach ($row['business_types'] ?? [] as $type) {
                $label = trim((string) $type);
                if ($label === '' || isset($owners[$label])) {
                    continue;
                }
                $owners[$label] = [
                    'key' => (string) $row['key'],
                    'label' => (string) ($row['label'] ?? $row['key']),
                ];
            }
        }

        return $owners;
    }

    /**
     * @param  list<string>  $types
     */
    public function assertExclusiveBusinessTypes(array $types, ?string $exceptKey = null): void
    {
        $types = $this->normalizeBusinessTypes($types);
        if ($types === []) {
            return;
        }

        $owners = $this->businessTypeOwners($exceptKey);
        $conflicts = [];
        foreach ($types as $type) {
            if (! isset($owners[$type])) {
                continue;
            }
            $conflicts[] = "{$type} is already on {$owners[$type]['label']}";
        }
        if ($conflicts !== []) {
            throw new \InvalidArgumentException(
                'Each business type can only belong to one setup type. '.implode(' ', $conflicts).'.'
            );
        }
    }

    /**
     * Catalog of known business types for Platform UI and public signup.
     *
     * @return list<array{value: string, label: string, industry: string}>
     */
    public function businessTypeCatalog(): array
    {
        return [
            ['value' => 'Pharmacy', 'label' => 'Pharmacy', 'industry' => 'commerce'],
            ['value' => 'Hardware', 'label' => 'Hardware', 'industry' => 'commerce'],
            ['value' => 'Retail shop', 'label' => 'Retail shop', 'industry' => 'commerce'],
            ['value' => 'Wholesale shop', 'label' => 'Wholesale shop', 'industry' => 'commerce'],
            ['value' => 'Supermarket', 'label' => 'Supermarket', 'industry' => 'commerce'],
            ['value' => 'Mini mart', 'label' => 'Mini mart / dukas', 'industry' => 'commerce'],
            ['value' => 'Shoe shop', 'label' => 'Shoe shop / footwear', 'industry' => 'commerce'],
            ['value' => 'Perfumes & cosmetics', 'label' => 'Perfumes & cosmetics', 'industry' => 'commerce'],
            ['value' => 'Fashion & clothing', 'label' => 'Fashion & clothing', 'industry' => 'commerce'],
            ['value' => 'Boutique', 'label' => 'Boutique', 'industry' => 'commerce'],
            ['value' => 'Electronics & phones', 'label' => 'Electronics & phones', 'industry' => 'commerce'],
            ['value' => 'Furniture & home', 'label' => 'Furniture & home', 'industry' => 'commerce'],
            ['value' => 'Agrovet', 'label' => 'Agrovet / agro supplies', 'industry' => 'commerce'],
            ['value' => 'Bookshop & stationery', 'label' => 'Bookshop & stationery', 'industry' => 'commerce'],
            ['value' => 'Butchery', 'label' => 'Butchery / meat shop', 'industry' => 'commerce'],
            ['value' => 'Liquor store', 'label' => 'Liquor store', 'industry' => 'commerce'],
            ['value' => 'Optical', 'label' => 'Optical / eyewear', 'industry' => 'commerce'],
            ['value' => 'Jewelry & accessories', 'label' => 'Jewelry & accessories', 'industry' => 'commerce'],
            ['value' => 'Pet shop', 'label' => 'Pet shop', 'industry' => 'commerce'],
            ['value' => 'Gift shop', 'label' => 'Gift shop', 'industry' => 'commerce'],
            ['value' => 'Sports & outdoor', 'label' => 'Sports & outdoor', 'industry' => 'commerce'],
            ['value' => 'Baby & kids', 'label' => 'Baby & kids shop', 'industry' => 'commerce'],
            ['value' => 'Warehouse', 'label' => 'Warehouse', 'industry' => 'commerce'],
            ['value' => 'Distribution', 'label' => 'Distribution / logistics', 'industry' => 'commerce'],
            ['value' => 'Hotel', 'label' => 'Hotel', 'industry' => 'hospitality'],
            ['value' => 'Lodge', 'label' => 'Lodge', 'industry' => 'hospitality'],
            ['value' => 'Bar / restaurant', 'label' => 'Bar / restaurant', 'industry' => 'hospitality'],
            ['value' => 'Café', 'label' => 'Café / coffee shop', 'industry' => 'hospitality'],
            ['value' => 'SPA / Salon / Kinyozi', 'label' => 'SPA / Salon / Kinyozi', 'industry' => 'spa'],
            ['value' => 'Barbershop', 'label' => 'Barbershop', 'industry' => 'spa'],
            ['value' => 'Driving school', 'label' => 'Driving school', 'industry' => 'driving'],
            ['value' => 'Other', 'label' => 'Other', 'industry' => null],
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    public function normalizeBusinessTypes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $allowed = array_fill_keys(
            array_column($this->businessTypeCatalog(), 'value'),
            true,
        );
        $out = [];
        foreach ($raw as $value) {
            $label = trim((string) $value);
            if ($label === '' || ! isset($allowed[$label]) || in_array($label, $out, true)) {
                continue;
            }
            $out[] = $label;
        }

        return $out;
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
     * @param  list<string>|null  $businessTypes
     * @return array{key: string, label: string, industry: string|null, custom: bool, application_ids: list<string>, applications: array<string, bool>, menu_groups: array<string, mixed>}
     */
    public function create(string $name, string $industry, ?array $businessTypes = null): array
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
        if (! in_array($industry, IndustryRegistry::ids(), true)) {
            throw new \InvalidArgumentException('Choose Retail & Distribution, Hotel & Hospitality, or SPA & Kinyozi.');
        }
        if ($this->labelTaken($name)) {
            throw new \InvalidArgumentException('A setup type with this name already exists.');
        }

        $normalizedTypes = $this->normalizeBusinessTypes($businessTypes ?? []);
        $catalogByValue = [];
        foreach ($this->businessTypeCatalog() as $row) {
            $catalogByValue[$row['value']] = $row['industry'] ?? null;
        }
        foreach ($normalizedTypes as $type) {
            $catalogIndustry = $catalogByValue[$type] ?? null;
            if ($catalogIndustry !== null && $catalogIndustry !== $industry) {
                throw new \InvalidArgumentException(
                    "{$type} belongs to a different industry than this setup type."
                );
            }
        }
        $this->assertExclusiveBusinessTypes($normalizedTypes);

        $key = $this->uniqueCustomKey($name);
        $applications = array_fill_keys($this->applicationIdsForIndustry($industry), false);
        // Turn on the primary industry app so the setup is usable for trial signup immediately.
        foreach ($this->applicationIdsForIndustry($industry) as $appId) {
            if (! in_array($appId, ['accounting', 'hr', 'admin', 'distribution'], true)) {
                $applications[$appId] = true;
                break;
            }
        }
        if (array_key_exists('admin', $applications)) {
            $applications['admin'] = true;
        }
        $menu = $this->applyApplicationGate($this->normalize(null), $applications);

        PlatformSetupProfile::query()->create([
            'profile_key' => $key,
            'menu_groups' => array_merge($menu, [
                'applications' => $applications,
                'business_types' => $normalizedTypes,
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
     * @return array{menu_groups: array<string, mixed>, applications: array<string, bool>, sales_platform: array<string, mixed>, payroll_platform: array<string, mixed>}
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

        $businessTypes = array_key_exists('business_types', $payload)
            ? $this->normalizeBusinessTypes($payload['business_types'])
            : $this->businessTypesForProfile($profileKey);
        $this->assertExclusiveBusinessTypes($businessTypes, $profileKey);

        $salesPlatform = array_replace(
            $this->salesPlatformSettingsForProfile($profileKey),
            is_array($payload['sales_platform'] ?? null)
                ? array_intersect_key($payload['sales_platform'], array_flip(self::SALES_PLATFORM_SETTING_KEYS))
                : [],
        );
        $payrollPlatform = array_replace(
            $this->payrollPlatformSettingsForProfile($profileKey),
            is_array($payload['payroll_platform'] ?? null)
                ? array_intersect_key($payload['payroll_platform'], array_flip([
                    'payroll_month_days_basis',
                    'shif_minimum_monthly',
                ]))
                : [],
        );
        $document = array_merge($menu, [
            'applications' => $applications,
            'business_types' => $businessTypes,
            'sales_platform' => $salesPlatform,
            'payroll_platform' => $payrollPlatform,
        ]);
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
            'business_types' => $businessTypes,
            'sales_platform' => $salesPlatform,
            'payroll_platform' => $payrollPlatform,
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

        return is_string($industry) && in_array($industry, IndustryRegistry::ids(), true)
            ? $industry
            : 'commerce';
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
            'business_types' => $this->businessTypesForProfile($key),
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
