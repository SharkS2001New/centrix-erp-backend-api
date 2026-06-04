<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeBankAccount extends Model
{
    use HasFactory;

    protected $table = 'employee_bank_accounts';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'bank_name',
        'bank_branch',
        'account_number',
        'account_name',
        'payment_method',
        'is_primary',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
