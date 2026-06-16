<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeKpi extends Model
{
    use HasFactory;

    protected $table = 'employee_kpis';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'organization_kpi_id',
        'kpi_code',
        'label',
        'period_start',
        'period_end',
        'target_value',
        'actual_value',
        'unit',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'target_value' => 'decimal:2',
        'actual_value' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function organizationKpi()
    {
        return $this->belongsTo(OrganizationKpi::class, 'organization_kpi_id');
    }

    public function progressPercent(): ?float
    {
        $target = $this->target_value;
        $actual = $this->actual_value;

        if ($target === null || (float) $target <= 0 || $actual === null) {
            return null;
        }

        return round(((float) $actual / (float) $target) * 100, 1);
    }
}
