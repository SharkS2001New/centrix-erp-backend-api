<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TillFloatSession extends Model
{
    use HasFactory;
    protected $table = 'till_float_sessions';
    public $timestamps = false;
    protected $fillable = [
        'till_id',
        'branch_id',
        'cashier_id',
        'handed_over_from',
        'handed_over_at',
        'session_date',
        'working_amount',
        'float_breakdown',
        'cash_movements',
        'closing_amount',
        'closing_denominations',
        'expected_amount',
        'cash_sales',
        'expenses_total',
        'opened_at',
        'suspended_at',
        'closed_at',
        'status',
        'notes',
    ];
    protected $casts = [
        'float_breakdown' => 'array',
        'cash_movements' => 'array',
        'closing_denominations' => 'array',
        'session_date' => 'date',
        'opened_at' => 'datetime',
        'suspended_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
