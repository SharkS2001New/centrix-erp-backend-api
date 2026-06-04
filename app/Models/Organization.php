<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Organization extends Model
{
    use HasFactory;

    protected $table = 'organizations';
    protected $fillable = [
        'company_code', 'logo', 'org_name', 'org_email', 'primary_tel',
        'secondary_tel', 'addn_tel1', 'addn_tel2', 'org_address', 'org_pin', 'vat_regno',
        'deployment_profile', 'enabled_modules', 'module_settings',
    ];
    protected $casts = [
        'enabled_modules' => 'array',
        'module_settings' => 'array',
    ];
}
