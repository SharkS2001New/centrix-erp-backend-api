<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrivingInstructor extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'user_id', 'instructor_number', 'full_name', 'phone',
        'email', 'licence_info', 'working_hours', 'lesson_pay', 'status',
    ];

    protected $casts = [
        'working_hours' => 'array',
        'lesson_pay' => 'decimal:2',
    ];

    public function lessons(): HasMany
    {
        return $this->hasMany(DrivingLesson::class, 'instructor_id');
    }
}
