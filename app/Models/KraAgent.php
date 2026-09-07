<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KraAgent extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'name',
        'comstore_base_url',
        'agent_last_seen_at',
        'agent_version',
    ];

    protected function casts(): array
    {
        return [
            'agent_last_seen_at' => 'datetime',
        ];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(KraAgentCommand::class);
    }
}
