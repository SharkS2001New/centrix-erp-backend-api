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
        'stock_location', 'invoice_number', 'cost_price', 'original_cost_price', 'received_by',
    ];

    protected $appends = [
        'received_by_name',
    ];

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
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