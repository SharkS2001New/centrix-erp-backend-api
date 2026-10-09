<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformLicenceCheckout extends Model
{
    protected $fillable = [
        'organization_id',
        'plan_id',
        'months',
        'amount',
        'discount_percent',
        'phone_number',
        'checkout_request_id',
        'mpesa_receipt',
        'status',
    ];

    protected $casts = [
        'months' => 'integer',
        'amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'plan_id');
    }
}
