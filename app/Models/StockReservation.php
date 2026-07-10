<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockReservation extends Model
{
    use HasFactory;

    protected $table = 'stock_reservations';
    public $timestamps = false;
    protected $fillable = [
        'branch_id', 'product_code', 'stock_location', 'quantity',
        'cart_id', 'cart_line_id', 'sale_id', 'reserved_by', 'expires_at', 'released_at',
    ];
    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }
}
