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
        'policy',
    ];

    protected $casts = [
        'policy' => 'array',
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
