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
        $canDelete = $this->status !== 'paid';

        return [
            'can_delete' => $canDelete,
            'delete_locked_after' => null,
            'delete_lock_minutes' => null,
            'delete_blocked_reason' => $canDelete
                ? null
                : 'Paid payroll runs cannot be deleted.',
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
