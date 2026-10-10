<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;

class DrivingRefund extends Model
{
    protected $fillable = [
        'organization_id', 'payment_id', 'amount', 'reason', 'authorized_by', 'refunded_on',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_on' => 'date',
    ];
}
