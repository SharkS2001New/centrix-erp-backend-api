<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaIncomingPayment extends Model
{
    protected $fillable = [
        'organization_id',
        'transaction_id',
        'phone_number',
        'amount',
        'applied_amount',
        'source',
        'status',
        'applied_cart_id',
        'stk_request_id',
        'received_at',
        'applied_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'applied_at' => 'datetime',
    ];
}
