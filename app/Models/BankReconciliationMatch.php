<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationMatch extends Model
{
    protected $fillable = [
        'bank_reconciliation_id',
        'bank_statement_line_id',
        'journal_entry_line_id',
        'match_type',
        'matched_amount',
        'matched_by',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_amount' => 'float',
            'matched_at' => 'datetime',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }
}
