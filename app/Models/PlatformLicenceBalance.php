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
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'token_balance' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
