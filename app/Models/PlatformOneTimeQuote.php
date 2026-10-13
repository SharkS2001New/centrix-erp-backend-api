<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformOneTimeQuote extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'emailed_to',
    ];

    protected $casts = [
        'emailed_to' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
