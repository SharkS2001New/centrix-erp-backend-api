<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingConnection extends Model
{
    protected $fillable = [
        'organization_id',
        'provider',
        'realm_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'last_error',
        'connected_at',
        'connected_by',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];
}
