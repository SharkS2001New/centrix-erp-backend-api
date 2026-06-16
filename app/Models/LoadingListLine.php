<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadingListLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loading_list_id',
        'line_no',
        'product_code',
        'product_name',
        'quantity',
        'quantity_label',
        'pack_breakdown',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
        'line_total' => 'float',
    ];

    public function loadingList(): BelongsTo
    {
        return $this->belongsTo(LoadingList::class, 'loading_list_id');
    }
}
