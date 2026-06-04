<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayPeriod extends Model
{
    use HasFactory;

    protected $table = 'pay_periods';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'period_code',
        'period_start',
        'period_end',
        'status',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
    ];
}
