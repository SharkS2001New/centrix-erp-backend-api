<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityCheckLine extends Model
{
    protected $table = 'hospitality_check_lines';

    protected $fillable = [
        'organization_id',
        'check_id',
        'product_id',
        'product_code',
        'description',
        'qty',
        'unit_price',
        'line_total',
        'vat_amount',
        'vat_id',
        'modifiers',
        'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'modifiers' => 'array',
        'sort_order' => 'integer',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(HospitalityCheck::class, 'check_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
