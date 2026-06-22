<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeFaceProfile extends Model
{
    use HasFactory;

    protected $table = 'employee_face_profiles';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'enrollment_photo_path',
        'face_embedding',
        'enrolled_at',
        'enrolled_device_identifier',
    ];

    protected $casts = [
        'face_embedding' => 'array',
        'enrolled_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
