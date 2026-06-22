<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeFingerprintProfile extends Model
{
    use HasFactory;

    protected $table = 'employee_fingerprint_profiles';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'fingerprint_template',
        'template_size',
        'scanner_model',
        'enrolled_at',
        'enrolled_device_identifier',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
