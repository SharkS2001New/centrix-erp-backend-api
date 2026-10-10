<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrivingEnrolment extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'student_id', 'course_id', 'package_id', 'status', 'enrolled_on',
        'price', 'registration_fee', 'lessons_required', 'lessons_completed',
    ];

    protected $casts = [
        'enrolled_on' => 'date',
        'price' => 'decimal:2',
        'registration_fee' => 'decimal:2',
        'lessons_required' => 'integer',
        'lessons_completed' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(DrivingStudent::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(DrivingCourse::class, 'course_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(DrivingCoursePackage::class, 'package_id');
    }
}
