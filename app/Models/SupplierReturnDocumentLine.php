<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnDocumentLine extends Model
{
    protected $fillable = [
        'document_id',
        'product_code',
        'product_name',
        'quantity',
        'package_type',
        'package_type_label',
        'uom_label',
        'stock_location',
        'reason',
        'lpo_txn_id',
        'stock_deduct_qty',
    ];

    protected $casts = [
        'quantity' => 'float',
        'stock_deduct_qty' => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SupplierReturnDocument::class, 'document_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }
}
