<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HikvisionAgentCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hikvision_agent_commands';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'attendance_clock_device_id',
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

    protected $casts = [
        'body_json' => 'array',
        'response_headers' => 'array',
        'response_status' => 'integer',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
