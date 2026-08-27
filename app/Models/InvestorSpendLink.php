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
        'supplier_id',
        'lpo_no',
        'amount',
        'spend_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'spend_date' => 'date',
        'supplier_id' => 'integer',
        'lpo_no' => 'integer',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(InvestorContribution::class, 'contribution_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
