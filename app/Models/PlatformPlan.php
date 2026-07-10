<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformPlan extends Model
{
    protected $fillable = [
        'name', 'code', 'description', 'interval', 'license_basis',
        'price', 'first_payment_price', 'renewal_price', 'currency',
        'seat_limit', 'workspace_keys', 'module_keys', 'is_active',
        'auto_invoice_template_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'first_payment_price' => 'decimal:2',
        'renewal_price' => 'decimal:2',
        'workspace_keys' => 'array',
        'module_keys' => 'array',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlatformSubscription::class, 'plan_id');
    }
}
