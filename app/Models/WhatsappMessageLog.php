<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessageLog extends Model
{
    public $timestamps = false;

    protected $table = 'whatsapp_message_logs';

    protected $fillable = [
        'organization_id',
        'conversation_id',
        'provider_message_id',
        'direction',
        'from_phone',
        'body',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }
}
