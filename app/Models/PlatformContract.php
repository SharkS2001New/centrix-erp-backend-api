<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformContract extends Model
{
    protected $fillable = [
        'kind', 'status', 'organization_id', 'plan_id', 'title', 'reference',
        'valid_until', 'start_date', 'end_date', 'currency', 'interval', 'license_basis',
        'amount', 'first_payment_price', 'renewal_price', 'seat_count',
        'workspace_keys', 'module_keys',
        'customer_name', 'customer_email', 'customer_phone', 'customer_address', 'customer_tax_pin',
        'terms', 'notes',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'amount' => 'decimal:2',
        'first_payment_price' => 'decimal:2',
        'renewal_price' => 'decimal:2',
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
}
