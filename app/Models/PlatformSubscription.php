<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSubscription extends Model
{
    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'seat_count',
        'current_period_start', 'current_period_end', 'is_trial', 'trial_ends_at',
        'first_payment_price', 'renewal_price', 'amount', 'currency',
        'license_basis', 'workspace_keys', 'module_keys', 'contract_id', 'invoice_id',
    ];

    protected $casts = [
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'trial_ends_at' => 'date',
        'is_trial' => 'boolean',
        'first_payment_price' => 'decimal:2',
        'renewal_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'workspace_keys' => 'array',
        'module_keys' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'plan_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'invoice_id');
    }
}
