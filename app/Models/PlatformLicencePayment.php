<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformLicencePayment extends Model
{
    protected $fillable = [
        'organization_id',
        'mpesa_transaction_id',
        'phone_number',
        'amount',
        'bill_ref_number',
        'business_short_code',
        'account_reference',
        'status',
        'match_method',
        'product',
        'days_added',
        'amount_applied',
        'balance_after',
        'period_end_before',
        'period_end_after',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_applied' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'period_end_before' => 'date',
        'period_end_after' => 'date',
        'days_added' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
