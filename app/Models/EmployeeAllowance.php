<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeAllowance extends Model
{
    use HasFactory;

    protected $table = 'employee_allowances';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'branch_id',
        'name',
        'amount',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public static function activeMonthlyTotal(int $employeeId): float
    {
        return (float) static::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->sum('amount');
    }

    /** @return array<int, array{name: string, amount: float}> */
    public static function activeLines(int $employeeId): array
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (self $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'amount' => (float) $row->amount,
            ])
            ->all();
    }
}
