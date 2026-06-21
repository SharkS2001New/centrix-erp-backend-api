<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BackgroundTask extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
        'user_id',
        'type',
        'status',
        'progress',
        'payload',
        'result',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function createPending(
        string $type,
        int $organizationId,
        ?int $userId,
        array $payload = [],
    ): self {
        return self::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'type' => $type,
            'status' => 'pending',
            'progress' => 0,
            'payload' => $payload,
        ]);
    }
}
