<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickingListLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'picking_list_id',
        'line_no',
        'product_code',
        'product_name',
        'shelf_location',
        'stock_location',
        'required_qty',
        'picked_qty',
        'shortage_qty',
        'quantity_label',
        'pack_breakdown',
        'shortage_reason',
    ];

    protected $casts = [
        'required_qty' => 'float',
        'picked_qty' => 'float',
        'shortage_qty' => 'float',
    ];

    public function pickingList(): BelongsTo
    {
        return $this->belongsTo(PickingList::class, 'picking_list_id');
    }
}
