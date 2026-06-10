<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaPaymentSkip extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cart_id',
        'mpesa_incoming_payment_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
