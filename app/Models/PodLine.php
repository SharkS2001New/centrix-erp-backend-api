<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PodLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'pod_record_id',
        'sale_item_id',
        'qty_ordered',
        'qty_delivered',
        'qty_refused',
        'reason',
    ];

    protected $casts = [
        'qty_ordered' => 'float',
        'qty_delivered' => 'float',
        'qty_refused' => 'float',
    ];

    public function podRecord(): BelongsTo
    {
        return $this->belongsTo(PodRecord::class, 'pod_record_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }
}
