<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversationMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'organization_id',
        'user_id',
        'role',
        'content',
        'tool_calls',
        'tool_results',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'tool_results' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
