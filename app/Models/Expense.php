<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'expenses';

    protected $fillable = [
        'branch_id',
        'expense_group_id',
        'float_session_id',
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
}
