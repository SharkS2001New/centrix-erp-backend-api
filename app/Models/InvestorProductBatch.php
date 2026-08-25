<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorProductBatch extends Model
{
    protected $fillable = [
        'organization_id',
        'investor_id',
        'contribution_id',
        'product_code',
        'product_name',
        'packaging',
        'qty_purchased',
        'qty_remaining',
        'unit_cost',
        'lpo_no',
        'lpo_txn_id',
        'stock_receipt_id',
        'received_at',
    ];

    protected $casts = [
        'qty_purchased' => 'float',
        'qty_remaining' => 'float',
        'unit_cost' => 'float',
        'received_at' => 'date',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(InvestorContribution::class, 'contribution_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }

    public function stockValue(): float
    {
        return round((float) $this->qty_remaining * (float) $this->unit_cost, 2);
    }
}
