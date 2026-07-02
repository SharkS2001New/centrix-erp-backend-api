<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'expenses';

    protected $fillable = [
        'branch_id',
        'expense_group_id',
        'float_session_id',
        'dispatch_trip_id',
        'description',
        'expense_amount',
        'expense_date',
        'balance_due',
        'invoice_no',
        'receipt_image',
        'billable_status',
        'payment_method_id',
        'recorded_by',
        'deleted_by',
        'deleted_at',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'deleted_at' => 'datetime',
        'billable_status' => 'integer',
    ];

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function expenseGroup()
    {
        return $this->belongsTo(ExpenseGroup::class, 'expense_group_id');
    }

    public function dispatchTrip()
    {
        return $this->belongsTo(DispatchTrip::class, 'dispatch_trip_id');
    }
}
