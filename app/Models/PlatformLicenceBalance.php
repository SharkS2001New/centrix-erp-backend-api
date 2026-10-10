<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformLicenceBalance extends Model
{
    protected $fillable = [
        'organization_id',
        'balance',
        'token_balance',
        'token_alert_level',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'token_balance' => 'integer',
        'token_alert_level' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
