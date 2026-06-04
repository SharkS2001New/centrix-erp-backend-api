<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollRun extends Model
{
    use HasFactory;

    protected $table = 'payroll_runs';
    public $timestamps = false;

    protected $fillable = [
        'pay_period_id',
        'run_date',
        'status',
        'processed_by',
        'total_gross',
        'total_net',
    ];

    public function payPeriod()
    {
        return $this->belongsTo(PayPeriod::class, 'pay_period_id');
    }
}
