<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LatenessWaiverRequest extends Model
{
    protected $table = 'lateness_waiver_requests';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'employee_attendance_id',
        'employee_id',
        'attendance_date',
        'late_minutes',
        'reason',
        'status',
        'waive',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'assigned_manager_user_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'waive' => 'boolean',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendance::class, 'employee_attendance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignedManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_manager_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
