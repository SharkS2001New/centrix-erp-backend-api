<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpesaPaybillAccount extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'primary_short_code',
        'shortcode',
        'till_number',
        'child_storecode',
        'branch_id',
        'route_id',
        'pos_till_id',
        'is_default',
        'is_active',
        'enable_stk_push',
        'env',
        'consumer_key',
        'consumer_secret',
        'passkey',
        'stk_callback_url',
        'c2b_confirmation_url',
        'c2b_validation_url',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'enable_stk_push' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $hidden = [
        'consumer_secret',
        'passkey',
    ];

    protected $appends = [
        'has_consumer_secret',
        'has_passkey',
        'has_own_daraja_credentials',
    ];

    public function getHasConsumerSecretAttribute(): bool
    {
        return trim((string) ($this->attributes['consumer_secret'] ?? '')) !== '';
    }

    public function getHasPasskeyAttribute(): bool
    {
        return trim((string) ($this->attributes['passkey'] ?? '')) !== '';
    }

    public function getHasOwnDarajaCredentialsAttribute(): bool
    {
        return trim((string) ($this->attributes['consumer_key'] ?? '')) !== ''
            || $this->has_consumer_secret
            || $this->has_passkey
            || trim((string) ($this->attributes['stk_callback_url'] ?? '')) !== ''
            || trim((string) ($this->attributes['c2b_confirmation_url'] ?? '')) !== ''
            || trim((string) ($this->attributes['c2b_validation_url'] ?? '')) !== '';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteModel::class, 'route_id');
    }

    public function posTill(): BelongsTo
    {
        return $this->belongsTo(Till::class, 'pos_till_id');
    }

    /** @return list<string> */
    public function allShortCodes(): array
    {
        $codes = [];
        foreach ([$this->primary_short_code, $this->child_storecode, $this->till_number, $this->shortcode] as $code) {
            $value = trim((string) $code);
            if ($value !== '' && ! in_array($value, $codes, true)) {
                $codes[] = $value;
            }
        }

        return $codes;
    }

    public function matchesShortCode(string $shortCode): bool
    {
        $needle = trim($shortCode);

        return $needle !== '' && in_array($needle, $this->allShortCodes(), true);
    }
}
