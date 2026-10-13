<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class PlatformPlan extends Model
{
    protected $fillable = [
        'name', 'code', 'industry', 'description', 'interval', 'license_basis',
        'price', 'first_payment_price', 'renewal_price',
        'monthly_price', 'yearly_price', 'token_pack_price', 'token_pack_days',
        'currency',
        'seat_limit', 'workspace_keys', 'module_keys', 'section_keys', 'feature_details',
        'self_serve', 'is_active', 'sort_order',
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
        'sort_order' => 'integer',
    ];

    public static function placeAtEnd(self $plan): void
    {
        if (! Schema::hasColumn('platform_plans', 'sort_order')) {
            return;
        }

        $next = (int) static::query()->where('id', '!=', $plan->id)->max('sort_order') + 1;
        $plan->forceFill(['sort_order' => $next])->save();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlatformSubscription::class, 'plan_id');
    }
}
