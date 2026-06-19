<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'users';
    protected $fillable = [
        'organization_id', 'branch_id', 'role_id', 'username', 'email', 'password',
        'full_name', 'is_admin', 'is_super_admin', 'access_scope', 'is_mobile_user', 'login_channels',
        'mobile_order_scope', 'assigned_route_id', 'is_active', 'last_login',
        'deleted_by', 'deleted_at',
    ];
    protected $hidden = ['password'];
    protected $casts = [
        'is_admin' => 'boolean',
        'is_super_admin' => 'boolean',
        'is_active' => 'boolean',
        'is_mobile_user' => 'boolean',
        'login_channels' => 'array',
        'last_login' => 'datetime',
        'deleted_at' => 'datetime',
        'can_use_all_channels' => 'boolean',
    ];

    protected $appends = [
        'allowed_customer_types',
        'can_use_all_channels',
    ];

    public function getAllowedCustomerTypesAttribute(): array
    {
        return app(\App\Services\Auth\UserMobileOrderScopeService::class)
            ->allowedCustomerTypes($this);
    }

    public function getCanUseAllChannelsAttribute(): bool
    {
        return app(\App\Services\Auth\UserMobileOrderScopeService::class)
            ->canUseAllChannels($this);
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function memberships()
    {
        return $this->hasMany(UserMembership::class);
    }
}
