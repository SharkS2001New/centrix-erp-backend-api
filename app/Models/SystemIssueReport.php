<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemIssueReport extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'user_id',
        'kind',
        'status',
        'message',
        'user_notes',
        'page_url',
        'api_path',
        'http_method',
        'http_status',
        'duration_ms',
        'context',
        'reported_by_user',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'reported_by_user' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
