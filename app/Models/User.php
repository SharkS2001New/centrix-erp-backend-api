<?php
namespace App\Models;

use App\Services\Auth\UsernameNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'organization_id', 'branch_id', 'role_id', 'username', 'email', 'email_verified_at', 'password',
        'full_name', 'is_admin', 'is_super_admin', 'access_scope', 'is_mobile_user', 'login_channels',
        'mobile_order_scope', 'assigned_route_id', 'is_active', 'must_change_password', 'password_expiry_skip_count',
        'password_changed_at', 'last_login',
        'two_factor_enabled', 'two_factor_method', 'two_factor_secret', 'two_factor_confirmed_at',
        'deleted_by', 'deleted_at',
    ];
    protected $hidden = ['password', 'two_factor_secret'];
    protected $casts = [
        'is_admin' => 'boolean',
        'is_super_admin' => 'boolean',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'is_mobile_user' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'email_verified_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
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

    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => UsernameNormalizer::forStorage($value),
        );
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<static>  $query */
    public function scopeWhereUsernameInsensitive($query, string $username)
    {
        return $query->whereRaw('UPPER(username) = ?', [
            UsernameNormalizer::forLookup($username),
        ]);
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
