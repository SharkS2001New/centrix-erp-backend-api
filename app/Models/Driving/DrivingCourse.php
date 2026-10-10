<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrivingCourse extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'code', 'name', 'licence_category', 'description',
        'theory_lessons', 'practical_lessons', 'lesson_minutes', 'price', 'registration_fee',
        'instalment_count', 'is_active',
    ];

    protected $casts = [
        'theory_lessons' => 'integer',
        'practical_lessons' => 'integer',
        'lesson_minutes' => 'integer',
        'price' => 'decimal:2',
        'registration_fee' => 'decimal:2',
        'instalment_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function enrolments(): HasMany
    {
        return $this->hasMany(DrivingEnrolment::class, 'course_id');
    }
}
