<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'customer_return_id',
        'sale_item_id',
        'product_code',
        'product_name',
        'uom',
        'quantity_sold',
        'return_qty',
        'unit_price',
        'amount',
        'line_no',
        'legacy_return_id',
    ];

    protected $casts = [
        'quantity_sold' => 'float',
        'return_qty' => 'float',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }
}
