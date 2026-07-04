<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InAppNotification extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'action_request_id',
        'type',
        'severity',
        'title',
        'message',
        'action_url',
        'is_read',
        'read_at',
        'resolved_at',
        'dismissed_at',
        'created_by',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'resolved_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionRequest(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class, 'action_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
