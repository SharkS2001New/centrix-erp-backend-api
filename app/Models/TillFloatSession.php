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
        'session_date',
        'working_amount',
        'float_breakdown',
        'closing_amount',
        'expected_amount',
        'cash_sales',
        'expenses_total',
        'opened_at',
        'closed_at',
        'status',
        'notes',
    ];
    protected $casts = [
        'float_breakdown' => 'array',
        'session_date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
