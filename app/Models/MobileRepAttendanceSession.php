<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileRepAttendanceSession extends Model
{
    protected $table = 'mobile_rep_attendance_sessions';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'user_id',
        'sign_in_at',
        'sign_out_at',
        'sign_in_latitude',
        'sign_in_longitude',
        'sign_out_latitude',
        'sign_out_longitude',
        'sign_in_address',
        'sign_out_address',
        'sign_in_photo_path',
        'sign_out_photo_path',
        'device_identifier',
    ];

    protected $casts = [
        'sign_in_at' => 'datetime',
        'sign_out_at' => 'datetime',
        'sign_in_latitude' => 'float',
        'sign_in_longitude' => 'float',
        'sign_out_latitude' => 'float',
        'sign_out_longitude' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->sign_out_at === null;
    }
}
