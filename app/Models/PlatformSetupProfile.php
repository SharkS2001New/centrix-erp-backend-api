<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetupProfile extends Model
{
    protected $fillable = [
        'profile_key',
        'menu_groups',
    ];

    protected $casts = [
        'menu_groups' => 'array',
    ];
}
