<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionRequest extends Model
{
    protected $fillable = [
        'organization_id',
        'type',
        'module',
        'reference_type',
        'reference_id',
        'requested_by',
        'assigned_to',
        'approver_permission',
        'status',
        'title',
        'reason',
        'payload',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class, 'action_request_id');
    }

    public function approvalActions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'action_request_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
