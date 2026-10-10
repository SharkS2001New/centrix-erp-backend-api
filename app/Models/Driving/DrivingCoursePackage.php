<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrivingCoursePackage extends Model
{
    protected $fillable = [
        'organization_id', 'course_id', 'name', 'price', 'registration_fee',
        'theory_lessons', 'practical_lessons', 'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'registration_fee' => 'decimal:2',
        'theory_lessons' => 'integer',
        'practical_lessons' => 'integer',
        'is_active' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(DrivingCourse::class, 'course_id');
    }
}
