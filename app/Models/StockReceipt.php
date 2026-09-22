<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceipt extends Model
{
    use HasFactory;

    protected $table = 'stock_receipts';

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_code', 'branch_id', 'organization_id', 'units_received',
        'stock_location', 'invoice_number', 'batch_no', 'expiry_date',
        'cost_price', 'original_cost_price', 'received_by',
        'expiry_cleared_at', 'expiry_cleared_by', 'expiry_cleared_qty',
        'expiry_clear_reason', 'expiry_clear_damage_id',
    ];

    protected $casts = [
        'expiry_date' => 'date:Y-m-d',
        'units_received' => 'float',
        'cost_price' => 'float',
        'original_cost_price' => 'float',
        'expiry_cleared_at' => 'datetime',
        'expiry_cleared_qty' => 'float',
    ];

    protected $appends = [
        'received_by_name',
    ];

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }

    public function getReceivedByNameAttribute(): ?string
    {
        if (! $this->received_by) {
            return null;
        }

        $this->loadMissing('receiver:id,full_name,username');
        $receiver = $this->getRelation('receiver');
        if (! $receiver) {
            return null;
        }

        $name = trim((string) ($receiver->full_name ?? ''));

        return $name !== '' ? $name : ($receiver->username ?? null);
    }
}