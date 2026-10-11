<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformPlan extends Model
{
    protected $fillable = [
        'name', 'code', 'industry', 'description', 'interval', 'license_basis',
        'price', 'first_payment_price', 'renewal_price',
        'monthly_price', 'yearly_price', 'token_pack_price', 'token_pack_days',
        'currency',
        'seat_limit', 'workspace_keys', 'module_keys', 'section_keys', 'feature_details',
        'self_serve', 'is_active',
        'auto_invoice_template_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'first_payment_price' => 'decimal:2',
        'renewal_price' => 'decimal:2',
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'token_pack_price' => 'decimal:2',
        'token_pack_days' => 'integer',
        'workspace_keys' => 'array',
        'module_keys' => 'array',
        'section_keys' => 'array',
        'feature_details' => 'array',
        'self_serve' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlatformSubscription::class, 'plan_id');
    }
}
