<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalePaymentAdjustment extends Model
{
    use HasFactory;

    protected $table = 'sale_payment_adjustments';

    protected $fillable = [
        'sale_id',
        'payment_method_id',
        'amount',
        'adjustment_type',
        'reference_number',
        'float_session_id',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
