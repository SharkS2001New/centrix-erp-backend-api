<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformMailMessage extends Model
{
    protected $fillable = [
        'direction', 'folder', 'mailbox_account_id', 'thread_key', 'message_id', 'in_reply_to',
        'from_address', 'from_name', 'to_addresses', 'cc_addresses',
        'subject', 'body_text', 'body_html',
        'organization_id', 'contract_id', 'sent_by_user_id',
        'read_at', 'sent_at', 'received_at', 'imap_uid', 'meta',
    ];

    protected $casts = [
        'to_addresses' => 'array',
        'cc_addresses' => 'array',
        'meta' => 'array',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
