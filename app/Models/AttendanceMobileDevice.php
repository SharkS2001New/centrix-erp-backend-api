<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceMobileDevice extends Model
{
    use HasFactory;

    protected $table = 'attendance_mobile_devices';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'device_identifier',
        'device_label',
        'platform',
        'is_active',
        'registered_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function registeredByUser()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
