<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankStatementLine extends Model
{
    protected $fillable = [
        'bank_reconciliation_id',
        'line_date',
        'description',
        'reference',
        'amount',
        'match_status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'line_date' => 'date',
            'amount' => 'float',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function match(): HasOne
    {
        return $this->hasOne(BankReconciliationMatch::class);
    }
}
