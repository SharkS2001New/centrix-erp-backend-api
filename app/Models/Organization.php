<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'organizations';
    protected $fillable = [
        'company_code', 'company_code_aliases', 'logo', 'org_name', 'org_email', 'primary_tel',
        'secondary_tel', 'addn_tel1', 'addn_tel2', 'org_address', 'org_pin', 'vat_regno',
        'deployment_profile', 'enabled_modules', 'module_settings', 'is_active',
    ];
    protected $casts = [
        'company_code_aliases' => 'array',
        'enabled_modules' => 'array',
        'module_settings' => 'array',
        'is_active' => 'boolean',
    ];

    public static function normalizeCompanyCodeIdentifier(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($code)) ?? '');
    }

    public static function findByCompanyCodeIdentifier(string $code): ?self
    {
        $normalized = self::normalizeCompanyCodeIdentifier($code);
        if ($normalized === '') {
            return null;
        }

        $primary = static::query()
            ->whereRaw(
                "UPPER(REPLACE(REPLACE(REPLACE(company_code, '-', ''), ' ', ''), '_', '')) = ?",
                [$normalized],
            )
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

    public function legacyArchiveCompanyCode(): ?string
    {
        $legacy = $this->module_settings['legacy_archive']['legacy_company_code'] ?? null;

        return filled($legacy) ? (string) $legacy : null;
    }

    public function matchesLegacyCompanyCode(string $legacyCode): bool
    {
        $normalized = self::normalizeCompanyCodeIdentifier($legacyCode);
        if ($normalized === '') {
            return false;
        }

        $candidates = array_filter([
            $this->company_code,
            $this->legacyArchiveCompanyCode(),
            ...($this->company_code_aliases ?? []),
        ]);

        foreach ($candidates as $candidate) {
            if (self::normalizeCompanyCodeIdentifier((string) $candidate) === $normalized) {
                return true;
            }
        }

        return false;
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
        ];
    }

    public static function logoIsStoredFile(?string $logo): bool
    {
        return \App\Support\OrganizationPublicStorage::isOrgScopedPath($logo);
    }

    /** @return array<string, mixed> */
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
            'deployment_profile',
            'enabled_modules',
            'module_settings',
            'is_active',
            'created_at',
            'updated_at',
        ]);
        $data['has_logo'] = self::logoIsStoredFile($this->logo);
        $data['logo_file_path'] = $data['has_logo'] ? "/organizations/{$this->id}/logo/file" : null;

        return $data;
    }
}
