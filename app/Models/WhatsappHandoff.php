<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappHandoff extends Model
{
    use BelongsToOrganizationCustomer;

    protected $table = 'whatsapp_handoffs';

    protected $fillable = [
        'organization_id',
        'conversation_id',
        'customer_num',
        'phone',
        'status',
        'customer_message',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
