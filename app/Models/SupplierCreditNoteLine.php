<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCreditNoteLine extends Model
{
    protected $fillable = [
        'supplier_credit_note_id',
        'product_code',
        'product_name',
        'description',
        'amount',
        'line_no',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SupplierCreditNote::class, 'supplier_credit_note_id');
    }
}
