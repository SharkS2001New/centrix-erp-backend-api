<?php

namespace App\Models;

use App\Services\Cache\OrganizationCache;
use App\Services\Erp\ErpContext;
use App\Support\OrganizationPublicStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::updated(function (self $organization): void {
            if ($organization->wasChanged('module_settings')) {
                OrganizationCache::forget(
                    (int) $organization->getKey(),
                    'kra.comstore_api_key',
                );
            }
        });
    }

    protected $table = 'organizations';

    protected $fillable = [
        'company_code', 'company_code_aliases', 'logo', 'primary_color', 'org_name', 'org_email', 'primary_tel',
        'secondary_tel', 'addn_tel1', 'addn_tel2', 'org_address', 'org_pin', 'vat_regno', 'business_type',
        'deployment_profile', 'enabled_modules', 'module_settings', 'is_active',
        'registration_source',
    ];

    protected $casts = [
        'company_code_aliases' => 'array',
        'enabled_modules' => 'array',
        'module_settings' => 'array',
        'is_active' => 'boolean',
    ];

    public static function normalizeCompanyCodeIdentifier(string $code): string
    {
        // Strip BOTH cases before uppercasing — the previous regex only kept
        // uppercase ASCII, so `moon-co` collapsed to '' and `Moon.Co` to 'MC'.
        // The SQL side (REGEXP_REPLACE with [^A-Za-z0-9]) always produced
        // MOONCO for the same input, so prior lowercase callers silently
        // routed through the aliases fallback. Fixing both sides to match.
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');
    }

    public static function findByCompanyCodeIdentifier(string $code): ?self
    {
        $normalized = self::normalizeCompanyCodeIdentifier($code);
        if ($normalized === '') {
            return null;
        }

        // Fast path: hits uq_organizations_company_code_normalized (added in
        // 2026_11_07_000004). The column is a STORED generated column whose
        // expression matches normalizeCompanyCodeIdentifier() exactly, so this
        // and the PHP normalizer always agree.
        $primary = static::query()
            ->where('company_code_normalized', $normalized)
            ->first();

        if ($primary) {
            return $primary;
        }

        return static::query()
            ->whereNotNull('company_code_aliases')
            ->get()
            ->first(fn (self $org) => collect($org->company_code_aliases ?? [])
                ->contains(fn ($alias) => self::normalizeCompanyCodeIdentifier((string) $alias) === $normalized));
    }

    /** Set at registration only — never updated afterward. */
    public static function immutableAttributes(): array
    {
        return ['company_code'];
    }

    /** Entitlements and billing identity — platform super admin only. */
    public static function platformControlledAttributes(): array
    {
        return [
            'org_email',
            'deployment_profile',
            'enabled_modules',
            'module_settings',
            'is_active',
        ];
    }

    /** Day-to-day profile fields organization admins may update. */
    public static function tenantManagedAttributes(): array
    {
        return [
            'org_name',
            'primary_tel',
            'secondary_tel',
            'addn_tel1',
            'addn_tel2',
            'org_address',
            'org_pin',
            'vat_regno',
            'primary_color',
        ];
    }

    /** Normalize #RGB / #RRGGBB (case-insensitive). Returns null when empty/invalid. */
    public static function normalizePrimaryColor(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (! preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $raw)) {
            return null;
        }
        if (strlen($raw) === 4) {
            $raw = sprintf('#%s%s%s%s%s%s', $raw[1], $raw[1], $raw[2], $raw[2], $raw[3], $raw[3]);
        }

        return strtoupper($raw);
    }

    public static function logoIsStoredFile(?string $logo): bool
    {
        return OrganizationPublicStorage::isOrgScopedPath($logo);
    }

    /**
     * Merge one module_settings section without clobbering concurrent updates to other sections.
     *
     * @param  array<string, mixed>  $values
     */
    public function putModuleSettingsSection(string $section, array $values): self
    {
        return $this->putModuleSettingsSections([$section => $values]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     */
    public function putModuleSettingsSections(array $sections): self
    {
        DB::transaction(function () use ($sections) {
            $organization = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $moduleSettings = is_array($organization->module_settings) ? $organization->module_settings : [];
            foreach ($sections as $section => $values) {
                $moduleSettings[$section] = $values;
            }
            $organization->update(['module_settings' => $moduleSettings]);
        });

        $this->refresh();

        // Settings PATCH responses re-read via CapabilityGate; drop request-scoped
        // org/gate caches so the same request sees these updates.
        app(ErpContext::class)->forgetOrganizationCache((int) $this->getKey());

        return $this;
    }

    public function toProfileArray(): array
    {
        $data = $this->only([
            'id',
            'company_code',
            'company_code_aliases',
            'org_name',
            'org_email',
            'primary_tel',
            'secondary_tel',
            'addn_tel1',
            'addn_tel2',
            'org_address',
            'org_pin',
            'vat_regno',
            'primary_color',
            'deployment_profile',
            'enabled_modules',
            'registration_source',
            'module_settings',
            'is_active',
            'created_at',
            'updated_at',
        ]);
        $data['primary_color'] = self::normalizePrimaryColor($data['primary_color'] ?? null);
        $data['has_logo'] = self::logoIsStoredFile($this->logo);
        $data['logo_file_path'] = $data['has_logo'] ? "/organizations/{$this->id}/logo/file" : null;

        return $data;
    }
}
