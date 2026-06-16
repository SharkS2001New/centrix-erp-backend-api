<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeEntry extends Model
{
    protected $fillable = [
        'organization_id',
        'created_by',
        'source',
        'topic',
        'path',
        'content',
        'confirmed',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $casts = [
        'confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
