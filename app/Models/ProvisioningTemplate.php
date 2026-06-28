<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProvisioningTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'deployment_profile',
        'enabled_modules',
        'sales_platform',
        'created_by',
    ];

    protected $casts = [
        'enabled_modules' => 'array',
        'sales_platform' => 'array',
    ];
}
