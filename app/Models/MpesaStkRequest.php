<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaStkRequest extends Model
{
    protected $fillable = [
        'cart_id',
        'organization_id',
        'phone_number',
        'amount',
        'merchant_request_id',
        'checkout_request_id',
        'transaction_id',
        'paid_amount',
        'status',
        'result_code',
        'result_desc',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];
}
