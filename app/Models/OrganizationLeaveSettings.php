<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationLeaveSettings extends Model
{
    protected $table = 'organization_leave_settings';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'annual_leave_days',
        'monthly_accrual_days',
        'months_for_full_annual',
        'sick_leave_days',
        'sick_leave_full_pay_days',
        'sick_leave_half_pay_days',
        'months_before_sick_eligibility',
    ];

    protected $casts = [
        'annual_leave_days' => 'decimal:2',
        'monthly_accrual_days' => 'decimal:2',
        'sick_leave_days' => 'decimal:2',
        'sick_leave_full_pay_days' => 'decimal:2',
        'sick_leave_half_pay_days' => 'decimal:2',
    ];

    public static function forOrganization(int $organizationId): self
    {
        return self::firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'annual_leave_days' => config('kenya_hr.annual_leave_days'),
                'monthly_accrual_days' => config('kenya_hr.monthly_accrual_days'),
                'months_for_full_annual' => config('kenya_hr.months_for_full_annual'),
                'sick_leave_days' => config('kenya_hr.sick_leave_days'),
                'sick_leave_full_pay_days' => config('kenya_hr.sick_leave_full_pay_days'),
                'sick_leave_half_pay_days' => config('kenya_hr.sick_leave_half_pay_days'),
                'months_before_sick_eligibility' => config('kenya_hr.months_before_sick_eligibility'),
            ],
        );
    }
}
