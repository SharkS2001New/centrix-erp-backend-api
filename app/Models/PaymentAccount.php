<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentAccount extends Model
{
    public const PROVIDER_MPESA = 'mpesa';

    public const PROVIDER_EQUITY = 'equity';

    public const PROVIDER_BANK = 'bank';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'provider',
        'account_type',
        'account_name',
        'account_number',
        'shortcode',
        'provider_account_type',
        'provider_account_id',
        'status',
        'is_default',
        'auto_match_payments',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'auto_match_payments' => 'boolean',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function providerAccount(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'provider_account_type', 'provider_account_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
