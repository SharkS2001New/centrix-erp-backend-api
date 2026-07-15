<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversation extends Model
{
    use BelongsToOrganizationCustomer;

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

    public function messageLogs(): HasMany
    {
        return $this->hasMany(WhatsappMessageLog::class, 'conversation_id');
    }
}
