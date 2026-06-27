<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TemporaryCart extends Model
{
    use HasFactory;

    protected $table = 'temporary_carts';
    protected $fillable = [
        'user_id', 'branch_id', 'channel', 'order_source', 'till_id', 'route_id', 'order_discount',
        'held_order_num', 'superseded_sale_id',
        'discount_voucher_id', 'payment_voucher_id', 'voucher_payment_amount', 'loyalty_card_id',
        'points_redeemed', 'points_payment_amount', 'mpesa_phone',
        'mpesa_payment_amount', 'mpesa_transaction_code', 'update_no',
    ];

    public function lines()
    {
        return $this->hasMany(CartLine::class, 'cart_id');
    }
}
