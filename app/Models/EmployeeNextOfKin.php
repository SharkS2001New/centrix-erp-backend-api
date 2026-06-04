<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeNextOfKin extends Model
{
    use HasFactory;

    protected $table = 'employee_next_of_kin';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'full_name',
        'relationship',
        'national_id',
        'phone',
        'address',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
