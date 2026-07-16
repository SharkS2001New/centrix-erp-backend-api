<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollRun extends Model
{
    use HasFactory;

    protected $table = 'payroll_runs';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'pay_period_id',
        'run_date',
        'status',
        'processed_by',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'payment_reference',
        'total_gross',
        'total_net',
    ];

    protected $casts = [
        'run_date' => 'date',
        'created_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'total_gross' => 'decimal:2',
        'total_net' => 'decimal:2',
    ];

    /** @return array<string, mixed> */
    public function deleteMeta(): array
    {
        $this->loadMissing('payPeriod');
        $schedule = app(\App\Services\Payroll\PayrollRunScheduleService::class);
        $created = $this->created_at;
        $orgId = (int) ($this->payPeriod?->organization_id ?? 0);
        $expires = $schedule->deleteLockExpiresAt($created, $this->run_date, $orgId ?: null);
        $lockMinutes = $orgId
            ? (int) \App\Services\Hr\HrPayrollSettingsResolver::forOrganizationId($orgId)['payroll_run_delete_lock_minutes']
            : \App\Services\Payroll\PayrollRunScheduleService::DELETE_LOCK_MINUTES;

        return [
            'can_delete' => $schedule->canDeletePayrollRun($created, $this->run_date, $orgId ?: null),
            'delete_locked_after' => $expires->toIso8601String(),
            'delete_lock_minutes' => $lockMinutes,
        ];
    }

    public function payPeriod()
    {
        return $this->belongsTo(PayPeriod::class, 'pay_period_id');
    }

    public function lines()
    {
        return $this->hasMany(PayrollLine::class, 'payroll_run_id');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidByUser()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function processedByUser()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
