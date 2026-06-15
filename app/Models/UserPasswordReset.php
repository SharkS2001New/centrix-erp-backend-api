<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPasswordReset extends Model
{
    public $timestamps = false;

    protected $table = 'user_password_resets';

    protected $fillable = [
        'user_id',
        'organization_id',
        'token_hash',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
