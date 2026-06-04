<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $table = 'users';
    protected $fillable = [
        'organization_id', 'branch_id', 'role_id', 'username', 'email', 'password',
        'full_name', 'is_admin', 'is_mobile_user', 'is_active', 'last_login',
        'deleted_by', 'deleted_at',
    ];
    protected $hidden = ['password'];
    protected $casts = [
        'is_active' => 'boolean',
        'last_login' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return $this->password;
    }
}
