<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrivingAttendanceLog extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'student_id', 'direction', 'recorded_at',
        'verification', 'mark', 'recorded_by',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(DrivingStudent::class, 'student_id');
    }
}
