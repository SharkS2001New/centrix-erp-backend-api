<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalePayment extends Model
{
    use HasFactory;
    protected $table = 'sale_payments';
    public $timestamps = false;
    protected $fillable = [
        'sale_id',
        'float_session_id',
        'payment_method_id',
        'amount',
        'reference_number',
        'paid_at',
    ];
    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
