<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'organization_id',
        'phone',
        'customer_num',
        'state',
        'payload',
        'last_message_at',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_message_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_num', 'customer_num');
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(WhatsappMessageLog::class, 'conversation_id');
    }
}
