<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollLine extends Model
{
    use HasFactory;

    protected $table = 'payroll_lines';
    public $timestamps = false;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'gross_pay',
        'nssf',
        'shif',
        'housing_levy',
        'paye',
        'other_deductions',
        'deductions',
        'net_pay',
        'taxable_income',
        'employer_nssf',
        'employer_housing',
        'statutory_meta',
    ];

    protected $casts = [
        'gross_pay' => 'decimal:2',
        'nssf' => 'decimal:2',
        'shif' => 'decimal:2',
        'housing_levy' => 'decimal:2',
        'paye' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'taxable_income' => 'decimal:2',
        'employer_nssf' => 'decimal:2',
        'employer_housing' => 'decimal:2',
        'statutory_meta' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
