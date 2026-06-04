<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeEmergencyContact extends Model
{
    use HasFactory;

    protected $table = 'employee_emergency_contacts';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'full_name',
        'relationship',
        'phone',
        'email',
        'address',
        'is_primary',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
