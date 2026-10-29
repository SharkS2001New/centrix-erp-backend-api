<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformBillingSetting extends Model
{
    protected $fillable = [
        'organization_id',
        'short_code',
        'account_name_prefix',
        'duration_discount_percent',
        'duration_discount_min_months',
        'policy',
        'free_plan_access',
        'manual_mpesa_account_name',
        'manual_mpesa_instructions',
        'stk_env',
        'stk_consumer_key',
        'stk_consumer_secret',
        'stk_shortcode',
        'stk_passkey',
        'stk_callback_url',
    ];

    protected $casts = [
        'policy' => 'array',
        'free_plan_access' => 'array',
        'duration_discount_percent' => 'decimal:2',
        'duration_discount_min_months' => 'integer',
        'stk_consumer_key' => 'encrypted',
        'stk_consumer_secret' => 'encrypted',
        'stk_passkey' => 'encrypted',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }
}
