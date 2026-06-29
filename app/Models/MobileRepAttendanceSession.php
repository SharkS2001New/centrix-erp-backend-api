<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileRepAttendanceSession extends Model
{
    public const CLOSE_REASON_SIGN_OUT = 'sign_out';

    public const CLOSE_REASON_IDLE_END_OF_DAY = 'idle_end_of_day';

    public const CLOSE_REASON_ADMIN = 'admin';

    protected $table = 'mobile_rep_attendance_sessions';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'user_id',
        'sign_in_at',
        'sign_out_at',
        'suspended_at',
        'last_resumed_at',
        'accumulated_work_seconds',
        'accumulated_suspended_seconds',
        'close_reason',
        'reopened_at',
        'reopened_by_user_id',
        'sign_in_latitude',
        'sign_in_longitude',
        'sign_out_latitude',
        'sign_out_longitude',
        'sign_in_address',
        'sign_out_address',
        'sign_in_photo_path',
        'sign_out_photo_path',
        'device_identifier',
        'attendance_id',
    ];

    protected $casts = [
        'sign_in_at' => 'datetime',
        'sign_out_at' => 'datetime',
        'suspended_at' => 'datetime',
        'last_resumed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'accumulated_work_seconds' => 'integer',
        'accumulated_suspended_seconds' => 'integer',
        'sign_in_latitude' => 'float',
        'sign_in_longitude' => 'float',
        'sign_out_latitude' => 'float',
        'sign_out_longitude' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isClosed(): bool
    {
        return $this->sign_out_at !== null;
    }

    /** Session not closed — may be active or suspended. */
    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function isSuspended(): bool
    {
        return $this->isOpen() && $this->suspended_at !== null;
    }

    public function isActive(): bool
    {
        return $this->isOpen() && $this->suspended_at === null;
    }
}
