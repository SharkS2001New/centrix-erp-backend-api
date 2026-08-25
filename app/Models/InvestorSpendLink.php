<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorSpendLink extends Model
{
    public const TYPE_SUPPLIER_PAYMENT = 'supplier_payment';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'organization_id',
        'investor_id',
        'contribution_id',
        'spend_type',
        'reference_id',
        'reference_label',
        'amount',
        'spend_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'spend_date' => 'date',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(InvestorContribution::class, 'contribution_id');
    }
}
