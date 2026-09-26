<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantFeedback extends Model
{
    protected $table = 'ai_assistant_feedback';

    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'rating',
        'workspace_id',
        'pathname',
        'user_message_preview',
        'assistant_message_preview',
        'note',
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
