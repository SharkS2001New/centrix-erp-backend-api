<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrganizationKpi extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'kpi_code',
        'label',
        'period_start',
        'period_end',
        'target_value',
        'unit',
        'notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'target_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function employeeKpis()
    {
        return $this->hasMany(EmployeeKpi::class, 'organization_kpi_id');
    }

    public function progressPercent(?float $actual, ?float $target = null): ?float
    {
        $target = $target ?? ($this->target_value !== null ? (float) $this->target_value : null);
        if ($target === null || $target <= 0 || $actual === null) {
            return null;
        }

        return round(($actual / $target) * 100, 1);
    }
}
