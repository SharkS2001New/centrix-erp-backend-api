<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrivingLesson extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'student_id', 'enrolment_id', 'instructor_id',
        'vehicle_id', 'lesson_type', 'starts_at', 'ends_at', 'location', 'notes',
        'outcome', 'feedback', 'attendance', 'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(DrivingStudent::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(DrivingInstructor::class, 'instructor_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(DrivingVehicle::class, 'vehicle_id');
    }
}
