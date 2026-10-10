<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;

class DrivingAssessment extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'enrolment_id', 'instructor_id', 'kind', 'title',
        'score', 'max_score', 'passed', 'comments', 'assessed_on', 'official_exam',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'passed' => 'boolean',
        'assessed_on' => 'date',
        'official_exam' => 'boolean',
    ];
}
