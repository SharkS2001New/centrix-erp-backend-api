<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrivingStudent extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'student_number', 'full_name', 'phone', 'email',
        'id_document_type', 'id_document_number', 'date_of_birth', 'address',
        'emergency_name', 'emergency_phone', 'registered_on', 'preferred_instructor_id',
        'status', 'notes',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'registered_on' => 'date',
    ];

    public function enrolments(): HasMany
    {
        return $this->hasMany(DrivingEnrolment::class, 'student_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(DrivingLesson::class, 'student_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(DrivingInvoice::class, 'student_id');
    }

    public function preferredInstructor(): BelongsTo
    {
        return $this->belongsTo(DrivingInstructor::class, 'preferred_instructor_id');
    }
}
