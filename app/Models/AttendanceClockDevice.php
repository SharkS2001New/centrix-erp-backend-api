<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Crypt;

class AttendanceClockDevice extends Model
{
    use HasFactory;

    protected $table = 'attendance_clock_devices';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'device_no',
        'location',
        'is_active',
        'provider',
        'host',
        'port',
        'username',
        'password_encrypted',
        'use_https',
        'last_synced_at',
        'last_event_at',
        'last_sync_error',
        'device_name',
        'device_info_json',
        'capabilities_json',
        'capabilities_fetched_at',
        'last_event_serial',
        'last_communication_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'use_https' => 'boolean',
        'port' => 'integer',
        'last_synced_at' => 'datetime',
        'last_event_at' => 'datetime',
        'last_communication_at' => 'datetime',
        'capabilities_fetched_at' => 'datetime',
        'device_info_json' => 'array',
        'capabilities_json' => 'array',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    protected $appends = [
        'has_password',
    ];

    public function getHasPasswordAttribute(): bool
    {
        return filled($this->password_encrypted);
    }

    public function setPlainPassword(?string $password): void
    {
        if ($password === null || $password === '') {
            return;
        }
        $this->password_encrypted = Crypt::encryptString($password);
    }

    public function plainPassword(): ?string
    {
        if (! filled($this->password_encrypted)) {
            return null;
        }
        try {
            return Crypt::decryptString($this->password_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Org-scoped implicit route binding ({attendance_clock_device} legacy routes). */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = static::query()->where($field ?? $this->getRouteKeyName(), $value);
        $user = request()->user();
        $request = request();

        if ($user && ! ($user->is_super_admin && ! $request->attributes->get('acting_organization_id'))) {
            app(\App\Services\Auth\UserAccessService::class)
                ->scopeOrganization($query, $user, 'organization_id', $request);
        }

        return $query->firstOrFail();
    }
}
