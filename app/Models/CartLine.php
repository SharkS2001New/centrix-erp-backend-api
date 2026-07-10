<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CartLine extends Model
{
    use HasFactory;

    protected $table = 'cart_lines';
    public $timestamps = false;
    protected $fillable = [
        'cart_id', 'product_code', 'product_name', 'unit_price', 'display_unit_price', 'quantity', 'uom',
        'product_vat', 'amount', 'discount_given', 'on_wholesale_retail', 'line_no', 'update_code',
    ];

    public function cart()
    {
        return $this->belongsTo(TemporaryCart::class, 'cart_id');
    }
}
