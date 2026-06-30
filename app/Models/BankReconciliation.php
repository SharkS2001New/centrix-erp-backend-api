<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    protected $fillable = [
        'organization_id',
        'chart_of_account_id',
        'title',
        'period_start',
        'period_end',
        'opening_balance',
        'statement_balance',
        'book_balance',
        'outstanding_receipts',
        'outstanding_payments',
        'adjusted_book_balance',
        'variance',
        'status',
        'notes',
        'imported_filename',
        'created_by',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'opening_balance' => 'float',
            'statement_balance' => 'float',
            'book_balance' => 'float',
            'outstanding_receipts' => 'float',
            'outstanding_payments' => 'float',
            'adjusted_book_balance' => 'float',
            'variance' => 'float',
            'completed_at' => 'datetime',
        ];
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }
}
