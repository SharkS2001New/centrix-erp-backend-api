<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'status',
        'error_code',
        'error_message',
        'tools_used',
        'latency_ms',
    ];

    protected $casts = [
        'tools_used' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'latency_ms' => 'integer',
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
