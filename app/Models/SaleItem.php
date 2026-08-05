<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SaleItem extends Model
{
    use HasFactory;

    protected $table = 'sale_items';
    public $timestamps = false;
    protected $fillable = [
        'sale_id', 'product_code', 'product_name', 'line_no', 'item_code', 'quantity', 'uom',
        'selling_price', 'display_unit_price', 'discount_given', 'product_vat', 'amount', 'on_wholesale_retail',
    ];

    public function product()
    {
        // Include soft-deleted catalogue rows so historical sales still resolve a name
        // when product_name was not snapshotted (legacy rows).
        return $this->belongsTo(Product::class, 'product_code', 'product_code')->withTrashed();
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
