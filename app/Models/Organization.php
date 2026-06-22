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
        'company_code', 'logo', 'org_name', 'org_email', 'primary_tel',
        'secondary_tel', 'addn_tel1', 'addn_tel2', 'org_address', 'org_pin', 'vat_regno',
        'deployment_profile', 'enabled_modules', 'module_settings', 'is_active',
    ];
    protected $casts = [
        'enabled_modules' => 'array',
        'module_settings' => 'array',
        'is_active' => 'boolean',
    ];

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
        return is_string($logo) && str_starts_with($logo, 'organizations/');
    }

    /** @return array<string, mixed> */
    public function toProfileArray(): array
    {
        $data = $this->only([
            'id',
            'company_code',
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
