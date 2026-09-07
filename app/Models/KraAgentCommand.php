<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KraAgentCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'kra_agent_id',
        'method',
        'path',
        'body_json',
        'accept',
        'status',
        'response_status',
        'response_headers',
        'response_body',
        'error_message',
        'created_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'body_json' => 'array',
            'response_headers' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(KraAgent::class, 'kra_agent_id');
    }
}
